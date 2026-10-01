<?php

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

/**
 * SLiMS core stock take (Stock Take → Current), read and scanned for the Klaras InvenSync API.
 *
 * Writes exactly what admin/modules/stock_take/stock_take_action.php writes, with prepared
 * statements and scoped to one session: the item's status goes from missing (m) to exists (e),
 * the session's lost/exists totals move by one, and the scanner joins stock_take_users.
 * Starting and finishing a session stay in SLiMS, since finishing may purge items.
 */
final class StockTake
{
    public const FOUND = 'found';
    public const ALREADY = 'already';
    public const ON_LOAN = 'on_loan';
    public const SKIPPED = 'skipped';
    public const UNKNOWN = 'unknown';

    /** stock_take_item.status for each list the app shows. */
    public const STATUSES = ['missing' => 'm', 'found' => 'e', 'on_loan' => 'l'];

    public function __construct(private PDO $db) {}

    private function query(string $sql, array $args = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement;
    }

    /**
     * The most recent sessions, the active one first.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(int $limit = 20): array
    {
        $rows = $this->query('SELECT * FROM stock_take ORDER BY is_active DESC, start_date DESC, stock_take_id DESC LIMIT ' . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'present'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function active(): ?array
    {
        $row = $this->query('SELECT * FROM stock_take WHERE is_active = 1 ORDER BY stock_take_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->present($row) : null;
    }

    /** @return array<string, mixed> */
    public function session(int $id): array
    {
        $row = $this->query('SELECT * FROM stock_take WHERE stock_take_id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Sesi stock opname tidak ditemukan.');
        }
        return $this->present($row);
    }

    /**
     * Counted from the items rather than read from the session's running totals, which the
     * SLiMS page only adjusts and can drift. Items of a finished session were purged by
     * SLiMS, so those fall back to the totals the session recorded.
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $id = (int) $row['stock_take_id'];
        $active = (int) $row['is_active'] === 1;
        $counts = $active
            ? $this->query('SELECT status, COUNT(*) FROM stock_take_item WHERE stock_take_id = ? GROUP BY status', [$id])->fetchAll(PDO::FETCH_KEY_PAIR)
            : [];
        $found = $active ? (int) ($counts['e'] ?? 0) : (int) $row['total_item_exists'];
        $missing = $active ? (int) ($counts['m'] ?? 0) : (int) $row['total_item_lost'];
        $onLoan = $active ? (int) ($counts['l'] ?? 0) : (int) $row['total_item_loan'];
        $users = @unserialize((string) ($row['stock_take_users'] ?? ''), ['allowed_classes' => false]);

        return [
            'id' => $id,
            'name' => (string) $row['stock_take_name'],
            'active' => $active,
            'started_at' => $row['start_date'],
            'ended_at' => $row['end_date'],
            'started_by' => (string) $row['init_user'],
            'total' => $active ? array_sum(array_map('intval', $counts)) : (int) $row['total_item_stock_taked'],
            'found' => $found,
            'missing' => $missing,
            'on_loan' => $onLoan,
            'participants' => is_array($users) ? array_values(array_map('strval', $users)) : [],
        ];
    }

    /**
     * Marks one copy found in the active session.
     *
     * @return array{outcome: string, item: array<string, mixed>|null}
     */
    public function scan(int $sessionId, string $code, string $scannedBy): array
    {
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 20) {
            return ['outcome' => self::UNKNOWN, 'item' => null];
        }
        $session = $this->query('SELECT is_active FROM stock_take WHERE stock_take_id = ?', [$sessionId])->fetchColumn();
        if ($session === false) {
            throw new RuntimeException('Sesi stock opname tidak ditemukan.');
        }
        if ((int) $session !== 1) {
            throw new RuntimeException('Sesi stock opname sudah ditutup.');
        }
        $this->db->beginTransaction();
        try {
            $item = $this->query('SELECT item_code, title, call_number, classification, location, status, checked_by, last_update FROM stock_take_item WHERE stock_take_id = ? AND item_code = ? FOR UPDATE', [$sessionId, $code])->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                $this->db->commit();
                $skipped = $this->query('SELECT 1 FROM item LEFT JOIN mst_item_status s ON s.item_status_id = item.item_status_id WHERE item.item_code = ? AND s.skip_stock_take = 1', [$code])->fetchColumn();
                return ['outcome' => $skipped ? self::SKIPPED : self::UNKNOWN, 'item' => null];
            }
            if ($item['status'] === 'l') {
                $this->db->commit();
                return ['outcome' => self::ON_LOAN, 'item' => self::item($item)];
            }
            if ($item['status'] === 'e') {
                $this->db->commit();
                return ['outcome' => self::ALREADY, 'item' => self::item($item)];
            }
            $now = date('Y-m-d H:i:s');
            $scannedBy = mb_substr($scannedBy, 0, 50);
            $this->query("UPDATE stock_take_item SET status = 'e', checked_by = ?, last_update = ? WHERE stock_take_id = ? AND item_code = ?", [$scannedBy, $now, $sessionId, $code]);
            $this->query('UPDATE stock_take SET total_item_lost = total_item_lost - 1, total_item_exists = total_item_exists + 1 WHERE stock_take_id = ?', [$sessionId]);
            $users = @unserialize((string) $this->query('SELECT stock_take_users FROM stock_take WHERE stock_take_id = ? FOR UPDATE', [$sessionId])->fetchColumn(), ['allowed_classes' => false]);
            $users = is_array($users) ? $users : [];
            if (!isset($users[$scannedBy])) {
                $users[$scannedBy] = $scannedBy;
                $this->query('UPDATE stock_take SET stock_take_users = ? WHERE stock_take_id = ?', [serialize($users), $sessionId]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        $item['status'] = 'e';
        $item['checked_by'] = $scannedBy;
        $item['last_update'] = $now;
        return ['outcome' => self::FOUND, 'item' => self::item($item)];
    }

    /**
     * One page of a session's copies in one state, newest check first for found copies.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function items(int $sessionId, string $status, string $search, int $page, int $perPage = 50): array
    {
        if (!isset(self::STATUSES[$status])) {
            throw new RuntimeException('Status eksemplar tidak valid.');
        }
        $where = 'stock_take_id = ? AND status = ?';
        $args = [$sessionId, self::STATUSES[$status]];
        if ($search !== '') {
            $where .= ' AND (item_code LIKE ? OR title LIKE ? OR call_number LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($args, $like, $like, $like);
        }
        $total = (int) $this->query("SELECT COUNT(*) FROM stock_take_item WHERE $where", $args)->fetchColumn();
        $order = $status === 'found' ? 'last_update DESC, item_code' : 'location, call_number, item_code';
        $page = max(1, $page);
        $rows = $this->query("SELECT item_code, title, call_number, classification, location, status, checked_by, last_update FROM stock_take_item WHERE $where ORDER BY $order LIMIT " . (int) $perPage . ' OFFSET ' . (($page - 1) * $perPage), $args)->fetchAll(PDO::FETCH_ASSOC);

        return ['rows' => array_map([self::class, 'item'], $rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /**
     * Every copy code of a session with its state, in code order after $after, so the app can
     * tell found, repeated and unknown codes apart while offline.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function codes(int $sessionId, string $after, int $limit = 5000): array
    {
        return $this->query('SELECT item_code, status FROM stock_take_item WHERE stock_take_id = ? AND item_code > ? ORDER BY item_code LIMIT ' . (int) $limit, [$sessionId, $after])->fetchAll(PDO::FETCH_NUM);
    }

    /** @return array<string, mixed> */
    public static function item(array $row): array
    {
        $states = array_flip(self::STATUSES);
        return [
            'code' => (string) $row['item_code'],
            'title' => (string) $row['title'],
            'call_number' => (string) ($row['call_number'] ?? ''),
            'classification' => (string) ($row['classification'] ?? ''),
            'location' => (string) ($row['location'] ?? ''),
            'status' => $states[$row['status']] ?? 'missing',
            'checked_by' => $row['checked_by'] ?? null,
            'checked_at' => $row['last_update'] ?? null,
        ];
    }
}
