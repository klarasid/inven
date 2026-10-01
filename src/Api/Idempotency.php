<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Request;

/**
 * Replays the answer to a change the app already sent.
 *
 * The app queues changes while offline and resends them until it hears back. A change sent
 * with an Idempotency-Key is applied once: the same key from the same librarian on the same
 * route gets the stored answer instead of a second item, finding or report. Only successful
 * answers are kept, for seven days, so a refused change can be corrected and sent again.
 */
final class Idempotency
{
    private const KEY = '/^[A-Za-z0-9_-]{8,64}$/D';
    /** A claim older than this belongs to a request that died; the next attempt may take it. */
    private const ABANDONED_SECONDS = 120;

    private function __construct(private PDO $db, private string $hash) {}

    public static function key(Request $request): ?string
    {
        $key = trim((string) ($request->header('Idempotency-Key') ?? ''));
        if ($key === '') {
            return null;
        }
        if (preg_match(self::KEY, $key) !== 1) {
            throw new ApiException('bad_request', 'Idempotency-Key tidak valid.', 400);
        }

        return $key;
    }

    /**
     * Claims the key, or returns the answer already stored for it.
     *
     * @return self|JsonResponse
     */
    public static function claim(PDO $db, int $userId, string $scope, string $key): self|JsonResponse
    {
        $hash = hash('sha256', $userId . '|' . $scope . '|' . $key);
        if (random_int(1, 50) === 1) {
            $db->prepare('DELETE FROM inventory_api_idempotency WHERE created_at < NOW() - INTERVAL 7 DAY LIMIT 500')->execute();
        }
        $db->prepare('DELETE FROM inventory_api_idempotency WHERE key_hash = ? AND status = 0 AND created_at < NOW() - INTERVAL ' . self::ABANDONED_SECONDS . ' SECOND')->execute([$hash]);
        $insert = $db->prepare('INSERT IGNORE INTO inventory_api_idempotency (key_hash, user_id, status, created_at) VALUES (?, ?, 0, NOW())');
        $insert->execute([$hash, $userId]);
        if ($insert->rowCount() === 1) {
            return new self($db, $hash);
        }
        $stored = $db->prepare('SELECT status, body FROM inventory_api_idempotency WHERE key_hash = ?');
        $stored->execute([$hash]);
        $row = $stored->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['status'] === 0) {
            throw new ApiException('request_in_progress', 'Perubahan ini sedang diproses. Tunggu sebentar.', 409);
        }
        $body = json_decode((string) $row['body'], true);

        return new JsonResponse((int) $row['status'], is_array($body) ? $body : [], ['Idempotent-Replay' => 'true']);
    }

    /** Keeps a successful answer; releases the key otherwise. */
    public function finish(?JsonResponse $response): void
    {
        if ($response !== null && $response->status >= 200 && $response->status < 300) {
            $this->db->prepare('UPDATE inventory_api_idempotency SET status = ?, body = ? WHERE key_hash = ?')
                ->execute([$response->status, json_encode($response->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $this->hash]);
            return;
        }
        $this->db->prepare('DELETE FROM inventory_api_idempotency WHERE key_hash = ?')->execute([$this->hash]);
    }
}
