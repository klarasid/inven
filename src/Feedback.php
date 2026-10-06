<?php

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/Telemetry.php';
require_once __DIR__ . '/UpdateCheck.php';

/**
 * Feedback librarians send to Klaras from the Masukan button, and what Klaras answers.
 *
 * A piece is kept here first and sent at once; when Klaras cannot be reached it waits and is sent
 * again with the daily usage report (Telemetry::sendIfDue). Each piece carries a token made here,
 * which is what lets this SLiMS, and only this one, read its status and replies back.
 *
 * What is sent: the message, its kind, the page it was written on, the library's name and address,
 * the versions of the plugin, SLiMS and PHP, and the librarian's name and email only when they tick
 * "Boleh dihubungi". Who wrote it is kept here either way, for the history.
 *
 * SLiMS opens its connection as utf8, which holds no emoji: they would be kept, and sent, as
 * "????". Everything here that reads or writes the feedback tables does so as utf8mb4 (unicode())
 * and gives the connection back as it found it.
 *
 * Runs on PHP 7.4 like the rest of the plugin.
 */
final class Feedback
{
    public const KINDS = ['bug' => 'Masalah', 'idea' => 'Saran', 'question' => 'Pertanyaan', 'praise' => 'Apresiasi'];
    /** 'pending' is ours: not sent yet. The others are Klaras's. */
    public const STATUSES = ['pending' => 'Menunggu terkirim', 'new' => 'Diterima', 'reviewing' => 'Ditinjau', 'planned' => 'Direncanakan', 'done' => 'Selesai', 'declined' => 'Tidak dilanjutkan'];
    public const CHECKED = 'inventory_feedback_checked';
    public const TRIED = 'inventory_feedback_tried';
    private const RETRY = 3600;
    private const REFRESH = 21600;
    private const REFRESH_FORCED = 60;
    private const MAX_FLUSH = 5;
    private const MAX_STATUS = 50;

    /** Tests replace the HTTP call: fn(string $url, array $payload): ?array (the decoded answer, null when it failed) */
    public static $transport = null;

    public static function endpoint(string $suffix = ''): string
    {
        return Telemetry::panelUrl() . '/api/v1/feedback/plugins/' . Telemetry::PLUGIN . $suffix;
    }

    /**
     * Keeps a piece of feedback and sends it. It is kept even when sending fails: it waits.
     *
     * @return array<string,mixed> the piece as list() shows it
     */
    public static function submit(PDO $db, array $input, int $uid, string $now): array
    {
        return self::unicode($db, static function () use ($db, $input, $uid, $now): array {
            return self::keep($db, $input, $uid, $now);
        });
    }

    /** @return array<string,mixed> */
    private static function keep(PDO $db, array $input, int $uid, string $now): array
    {
        $kind = is_scalar($input['kind'] ?? null) ? (string) $input['kind'] : '';
        if (!isset(self::KINDS[$kind])) throw new RuntimeException('Pilih jenis masukan.');
        $message = trim(str_replace("\r\n", "\n", is_scalar($input['message'] ?? null) ? (string) $input['message'] : ''));
        if (mb_strlen($message) < 10) throw new RuntimeException('Tulis masukan Anda, paling sedikit 10 karakter.');
        if (mb_strlen($message) > 5000) throw new RuntimeException('Masukan maksimal 5.000 karakter.');
        $page = is_scalar($input['page'] ?? null) ? mb_substr(preg_replace('/[^A-Za-z0-9_:\/-]/', '', (string) $input['page']), 0, 100) : '';
        $contact = in_array($input['contact'] ?? '', ['1', 1, true, 'true'], true);

        $db->prepare('INSERT INTO inventory_feedback (token, kind, message, page, user_id, contact, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([bin2hex(random_bytes(24)), $kind, $message, $page, $uid ?: null, $contact ? 1 : 0, 'pending', $now]);
        $id = (int) $db->lastInsertId();
        Telemetry::count('feedback', $db);
        self::send($db, self::row($db, $id), $now);

        foreach (self::list($db) as $piece) {
            if ($piece['id'] === $id) return $piece;
        }
        throw new RuntimeException('Masukan tidak tersimpan.');
    }

    /**
     * Whether there is anything to do after a page: feedback waiting to be sent (tried at most
     * hourly while Klaras cannot be reached) or replies to ask for (every six hours). Cheap: two
     * small queries, and never an error before the migration has run.
     */
    public static function due(PDO $db): bool
    {
        try {
            $waiting = (bool) $db->query('SELECT 1 FROM inventory_feedback WHERE panel_id IS NULL LIMIT 1')->fetchColumn();
            if ($waiting && time() - (int) self::setting($db, self::TRIED) >= self::RETRY) return true;
            $sent = (bool) $db->query('SELECT 1 FROM inventory_feedback WHERE panel_id IS NOT NULL LIMIT 1')->fetchColumn();
            return $sent && time() - (int) self::setting($db, self::CHECKED) >= self::REFRESH;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** What due() found to do, after the page has been delivered. Never fails the page. */
    public static function background(PDO $db): void
    {
        try {
            if ((bool) $db->query('SELECT 1 FROM inventory_feedback WHERE panel_id IS NULL LIMIT 1')->fetchColumn() && time() - (int) self::setting($db, self::TRIED) >= self::RETRY) {
                self::store($db, self::TRIED, (string) time());
                self::flush($db);
            }
            self::refresh($db);
        } catch (\Throwable $error) {
        }
    }

    /** Sends what is still waiting, a few at a time. @return int how many were sent */
    public static function flush(PDO $db): int
    {
        return self::unicode($db, static function () use ($db): int { return self::flushNow($db); });
    }

    private static function flushNow(PDO $db): int
    {
        $sent = 0;
        $waiting = $db->query("SELECT * FROM inventory_feedback WHERE panel_id IS NULL ORDER BY id LIMIT " . self::MAX_FLUSH)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($waiting as $row) {
            if (self::send($db, $row, date('Y-m-d H:i:s'))) $sent++;
        }
        return $sent;
    }

    /**
     * Asks Klaras how the feedback sent from here stands, and keeps the replies it has not seen.
     * Asked at most every six hours, or every minute when a librarian opens the history.
     */
    public static function refresh(PDO $db, bool $force = false): bool
    {
        return self::unicode($db, static function () use ($db, $force): bool { return self::refreshNow($db, $force); });
    }

    private static function refreshNow(PDO $db, bool $force): bool
    {
        $checked = (int) self::setting($db, self::CHECKED);
        if (time() - $checked < ($force ? self::REFRESH_FORCED : self::REFRESH)) return false;
        $rows = $db->query('SELECT id, panel_id, token FROM inventory_feedback WHERE panel_id IS NOT NULL ORDER BY id DESC LIMIT ' . self::MAX_STATUS)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return false;
        self::store($db, self::CHECKED, (string) time());

        $answer = self::post(self::endpoint('/status'), [
            'install_id' => Telemetry::state($db)['install_id'],
            'items' => array_map(static function (array $row): array { return ['id' => $row['panel_id'], 'token' => $row['token']]; }, $rows),
        ]);
        if (!is_array($answer['data'] ?? null)) return false;

        $ids = array_column($rows, 'id', 'panel_id');
        $now = date('Y-m-d H:i:s');
        $status = $db->prepare('UPDATE inventory_feedback SET status = ?, issue_url = ? WHERE id = ?');
        // Checked, then added: a reply is kept once, and the SQL stays plain enough for the tests' SQLite.
        $known = $db->prepare('SELECT 1 FROM inventory_feedback_replies WHERE feedback_id = ? AND panel_reply_id = ?');
        $reply = $db->prepare('INSERT INTO inventory_feedback_replies (feedback_id, panel_reply_id, kind, message, replied_at, received_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($answer['data'] as $piece) {
            $id = $ids[(string) ($piece['id'] ?? '')] ?? null;
            if ($id === null || !isset(self::STATUSES[(string) ($piece['status'] ?? '')])) continue;
            $url = is_string($piece['issue_url'] ?? null) && preg_match('#\Ahttps://github\.com/[\w.-]+/[\w.-]+/issues/\d+\z#', $piece['issue_url']) ? $piece['issue_url'] : null;
            $status->execute([(string) $piece['status'], $url, $id]);
            foreach (is_array($piece['replies'] ?? null) ? $piece['replies'] : [] as $answered) {
                if (!is_array($answered) || !is_int($answered['id'] ?? null) || !is_string($answered['message'] ?? null)) continue;
                $known->execute([$id, $answered['id']]);
                if ($known->fetchColumn()) continue;
                $reply->execute([$id, $answered['id'], mb_substr((string) ($answered['kind'] ?? 'manual'), 0, 20), mb_substr($answered['message'], 0, 5000), self::datetime($answered['created_at'] ?? null, $now), $now]);
            }
        }
        return true;
    }

    /**
     * The feedback sent from this SLiMS, newest first, each with who wrote it and Klaras's replies.
     *
     * @return list<array<string,mixed>>
     */
    public static function list(PDO $db, int $limit = 50): array
    {
        return self::unicode($db, static function () use ($db, $limit): array { return self::listNow($db, $limit); });
    }

    /** @return list<array<string,mixed>> */
    private static function listNow(PDO $db, int $limit): array
    {
        $rows = $db->query('SELECT f.*, u.realname FROM inventory_feedback f LEFT JOIN user u ON u.user_id = f.user_id ORDER BY f.id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $ids = array_map('intval', array_column($rows, 'id'));
        $query = $db->prepare('SELECT * FROM inventory_feedback_replies WHERE feedback_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY replied_at, id');
        $query->execute($ids);
        $replies = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $reply) $replies[(int) $reply['feedback_id']][] = $reply;

        return array_map(static function (array $row) use ($replies): array {
            $own = $replies[(int) $row['id']] ?? [];
            $seen = (string) ($row['seen_at'] ?? '');
            return [
                'id' => (int) $row['id'],
                'kind' => ['key' => (string) $row['kind'], 'label' => self::KINDS[$row['kind']] ?? (string) $row['kind']],
                'message' => (string) $row['message'],
                'page' => (string) $row['page'],
                'author' => (string) ($row['realname'] ?? ''),
                'contact' => (bool) $row['contact'],
                'status' => ['key' => (string) $row['status'], 'label' => self::STATUSES[$row['status']] ?? (string) $row['status']],
                'issue_url' => $row['issue_url'] === null ? null : (string) $row['issue_url'],
                'created_at' => (string) $row['created_at'],
                'replies' => array_map(static function (array $reply) use ($seen): array {
                    return ['message' => (string) $reply['message'], 'kind' => (string) $reply['kind'], 'replied_at' => (string) $reply['replied_at'], 'unread' => $seen === '' || (string) $reply['received_at'] > $seen];
                }, $own),
            ];
        }, $rows);
    }

    /** Replies no one has opened the history to read yet. */
    public static function unread(PDO $db): int
    {
        return (int) $db->query("SELECT COUNT(*) FROM inventory_feedback_replies r JOIN inventory_feedback f ON f.id = r.feedback_id WHERE f.seen_at IS NULL OR r.received_at > f.seen_at")->fetchColumn();
    }

    public static function markSeen(PDO $db, string $now): void
    {
        $db->prepare('UPDATE inventory_feedback SET seen_at = ?')->execute([$now]);
    }

    /** What would be sent as the contact of the staff member $uid, shown to them before they allow it. @return array{name:string,email:string} */
    public static function contact(PDO $db, int $uid): array
    {
        try {
            $query = $db->prepare('SELECT realname, email FROM user WHERE user_id = ?');
            $query->execute([$uid]);
            $row = $query->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $error) {
            // A SLiMS whose user table has no email column: the name alone.
            $query = $db->prepare('SELECT realname FROM user WHERE user_id = ?');
            $query->execute([$uid]);
            $row = $query->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $email = trim((string) ($row['email'] ?? ''));
        return ['name' => mb_substr(trim((string) ($row['realname'] ?? '')), 0, 100), 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''];
    }

    /** @param array<string,mixed> $row */
    private static function send(PDO $db, array $row, string $now): bool
    {
        $plugin = UpdateCheck::plugin();
        $sysconf = isset($GLOBALS['sysconf']) && is_array($GLOBALS['sysconf']) ? $GLOBALS['sysconf'] : [];
        $payload = [
            'install_id' => Telemetry::state($db)['install_id'],
            'token' => (string) $row['token'],
            'kind' => (string) $row['kind'],
            'message' => (string) $row['message'],
            'page' => (string) $row['page'] !== '' ? (string) $row['page'] : null,
            'library' => ['name' => isset($sysconf['library_name']) ? mb_substr((string) $sysconf['library_name'], 0, 255) : null, 'site_url' => Telemetry::siteUrl()],
            'environment' => [
                'plugin_version' => $plugin['version'],
                'slims_version' => defined('SENAYAN_VERSION_TAG') ? substr((string) SENAYAN_VERSION_TAG, 0, 50) : null,
                'php_version' => PHP_VERSION,
            ],
        ];
        if ((int) $row['contact'] === 1 && (int) $row['user_id'] > 0) {
            $contact = self::contact($db, (int) $row['user_id']);
            $payload['contact'] = array_filter(['name' => $contact['name'], 'email' => $contact['email']], static function ($value) { return $value !== ''; });
        }

        $answer = self::post(self::endpoint(), $payload);
        $id = is_array($answer['data'] ?? null) ? (string) ($answer['data']['id'] ?? '') : '';
        if (!preg_match('/\A[0-9a-f-]{36}\z/', $id)) {
            $db->prepare('UPDATE inventory_feedback SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $row['id']]);
            return false;
        }
        $db->prepare('UPDATE inventory_feedback SET panel_id = ?, status = ?, sent_at = ?, attempts = attempts + 1 WHERE id = ?')
            ->execute([$id, isset(self::STATUSES[(string) ($answer['data']['status'] ?? '')]) ? (string) $answer['data']['status'] : 'new', $now, (int) $row['id']]);
        return true;
    }

    /** @return array<string,mixed> */
    private static function row(PDO $db, int $id): array
    {
        $query = $db->prepare('SELECT * FROM inventory_feedback WHERE id = ?');
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null the decoded answer of a 2xx, null otherwise */
    private static function post(string $url, array $payload): ?array
    {
        if (is_callable(self::$transport)) return call_user_func(self::$transport, $url, $payload);
        if (!function_exists('curl_init')) return null;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: klaras-inven/' . UpdateCheck::plugin()['version']],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status < 200 || $status >= 300 || !is_string($body)) return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Runs $work with the connection speaking utf8mb4, so emoji are kept and read back whole, then
     * gives the connection back the character set it had. Only MySQL needs it.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private static function unicode(PDO $db, callable $work)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return $work();
        $client = (string) $db->query('SELECT @@character_set_client')->fetchColumn();
        if ($client === 'utf8mb4') return $work();
        $db->query('SET NAMES utf8mb4');
        try {
            return $work();
        } finally {
            // As SLiMS opened it (lib/Connection.php): SET NAMES, then the character set it reads with.
            $restore = preg_match('/\A[a-z0-9_]+\z/', $client) ? $client : 'utf8';
            $db->query('SET NAMES ' . $restore);
            $db->query('SET CHARACTER SET ' . $restore);
        }
    }

    private static function datetime($value, string $fallback): string
    {
        $time = is_string($value) ? strtotime($value) : false;
        return $time === false ? $fallback : date('Y-m-d H:i:s', $time);
    }

    private static function setting(PDO $db, string $name): string
    {
        $query = $db->prepare('SELECT setting_value FROM setting WHERE setting_name = ?');
        $query->execute([$name]);
        $value = $query->fetchColumn();
        $decoded = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : null;
        return is_scalar($decoded) ? (string) $decoded : '';
    }

    private static function store(PDO $db, string $name, string $value): void
    {
        $update = $db->prepare('UPDATE setting SET setting_value = ? WHERE setting_name = ?');
        $update->execute([serialize($value), $name]);
        if ($update->rowCount() === 0 && self::setting($db, $name) !== $value) {
            $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?)')->execute([$name, serialize($value)]);
        }
    }
}
