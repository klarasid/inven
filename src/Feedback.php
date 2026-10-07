<?php

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/Telemetry.php';
require_once __DIR__ . '/UpdateCheck.php';
require_once __DIR__ . '/PhotoStorage.php';

/**
 * Feedback librarians send to Klaras from the Masukan button, and what Klaras answers. Each staff
 * member sees, and is told of replies to, only what they sent.
 *
 * A thread goes both ways (migration 22): the librarian may answer in it, usually with the detail
 * Klaras asked for (awaiting_reply). An answer is kept as a reply of kind "sender" and, like
 * the feedback itself, waits when Klaras cannot be reached. Klaras closes a thread to answers 30
 * days after it is settled (can_reply).
 *
 * A piece is kept here first and sent at once; when Klaras cannot be reached it waits and is sent
 * again with the daily usage report (Telemetry::sendIfDue). Each piece carries a token made here,
 * which is what lets this SLiMS, and only this one, read its status and replies back.
 *
 * What is sent: the message, its kind, the page it was written on, the library's name and address,
 * the versions of the plugin, SLiMS and PHP, and the librarian's name and email only when they tick
 * "Boleh dihubungi". Who wrote it is kept here either way, for the history.
 *
 * A problem is told in three parts (what the librarian did, what happened, what should have happened),
 * kept as one message the way Klaras Panel does (App\Feedback\ProblemReport). Up to three screenshots
 * may go with a piece (migration 23): kept in images/inventaris-barang/masukan/ until Klaras has
 * them, each sent on its own after the feedback, then deleted here, since they may show member data.
 *
 * SLiMS opens its connection as utf8, which holds no emoji: they would be kept, and sent, as
 * "????". Everything here that reads or writes the feedback tables does so as utf8mb4 (unicode())
 * and gives the connection back as it found it.
 *
 * Needs PHP 8.1 and SLiMS 9.8, like the rest of the plugin.
 */
final class Feedback
{
    public const KINDS = ['bug' => 'Masalah', 'idea' => 'Saran', 'question' => 'Pertanyaan', 'praise' => 'Apresiasi'];
    /** 'pending' is ours: not sent yet. The others are Klaras's. */
    public const STATUSES = ['pending' => 'Menunggu terkirim', 'new' => 'Diterima', 'reviewing' => 'Ditinjau', 'planned' => 'Direncanakan', 'done' => 'Selesai', 'declined' => 'Tidak dilanjutkan'];
    /** Shown instead of the status while Klaras waits on the librarian; Klaras itself reports "reviewing". */
    public const AWAITING = 'Perlu jawaban Anda';
    /** Replies the librarian wrote: sent or waiting ("sender"), or refused because the thread had closed. */
    private const OWN = ['sender', 'refused'];
    public const CHECKED = 'inventory_feedback_checked';
    public const TRIED = 'inventory_feedback_tried';
    private const RETRY = 3600;
    private const REFRESH = 21600;
    private const REFRESH_FORCED = 60;
    private const MAX_FLUSH = 5;
    private const MAX_STATUS = 50;
    public const MAX_SCREENSHOTS = 3;
    public const MAX_SCREENSHOT_BYTES = 5242880;
    /** What a screenshot may be, by its contents, and the extension it is kept under. */
    private const SCREENSHOT_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    /** One part of a problem, at most, as Klaras Panel has it (ProblemReport::MAX_PART). */
    private const PART_MAX = 1500;

    /** Where screenshots wait to be sent. Tests point it at a folder of their own. */
    public static $directory = null;

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
    public static function submit(PDO $db, array $input, int $uid, string $now, array $screenshots = []): array
    {
        return self::unicode($db, static function () use ($db, $input, $uid, $now, $screenshots): array {
            return self::keep($db, $input, $uid, $now, $screenshots);
        });
    }

    /**
     * @param list<array{bytes:string,name:string}> $screenshots
     * @return array<string,mixed>
     */
    private static function keep(PDO $db, array $input, int $uid, string $now, array $screenshots): array
    {
        $kind = is_scalar($input['kind'] ?? null) ? (string) $input['kind'] : '';
        if (!isset(self::KINDS[$kind])) throw new RuntimeException('Pilih jenis masukan.');
        // A problem in its three parts; a page loaded before 2.13 may still send one message.
        $message = $kind === 'bug' && array_key_exists('happened', $input) ? self::problem($input) : self::text($input['message'] ?? null);
        if (mb_strlen($message) < 10) throw new RuntimeException('Tulis masukan Anda, paling sedikit 10 karakter.');
        if (mb_strlen($message) > 5000) throw new RuntimeException('Masukan maksimal 5.000 karakter.');
        $screenshots = self::screenshots($db, $screenshots);
        $page = is_scalar($input['page'] ?? null) ? mb_substr(preg_replace('/[^A-Za-z0-9_:\/-]/', '', (string) $input['page']), 0, 100) : '';
        $contact = in_array($input['contact'] ?? '', ['1', 1, true, 'true'], true);

        $db->prepare('INSERT INTO inventory_feedback (token, kind, message, page, user_id, contact, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([bin2hex(random_bytes(24)), $kind, $message, $page, $uid ?: null, $contact ? 1 : 0, 'pending', $now]);
        $id = (int) $db->lastInsertId();
        foreach ($screenshots as $screenshot) self::keepScreenshot($db, $id, $screenshot, $now);
        Telemetry::count('feedback', $db);
        if (self::send($db, self::row($db, $id), $now) && $screenshots) self::sendScreenshots($db, $id);

        foreach (self::list($db, $uid) as $piece) {
            if ($piece['id'] === $id) return $piece;
        }
        throw new RuntimeException('Masukan tidak tersimpan.');
    }

    /**
     * A problem's three parts as one message, headed as Klaras Panel heads them. The last is optional.
     */
    private static function problem(array $input): string
    {
        $did = self::text($input['did'] ?? null);
        $happened = self::text($input['happened'] ?? null);
        $expected = self::text($input['expected'] ?? null);
        if (mb_strlen($did) < 3) throw new RuntimeException('Ceritakan apa yang Anda lakukan sebelum masalah muncul.');
        if (mb_strlen($happened) < 10) throw new RuntimeException('Ceritakan apa yang terjadi, paling sedikit 10 karakter.');
        foreach ([$did, $happened, $expected] as $part) {
            if (mb_strlen($part) > self::PART_MAX) throw new RuntimeException('Tiap isian maksimal 1.500 karakter.');
        }
        $parts = array_filter(['Yang saya lakukan' => $did, 'Yang terjadi' => $happened, 'Yang seharusnya terjadi' => $expected], static function (string $text): bool { return $text !== ''; });
        return implode("\n\n", array_map(static function (string $heading, string $text): string { return $heading . ":\n" . $text; }, array_keys($parts), $parts));
    }

    private static function text($value): string
    {
        return trim(str_replace("\r\n", "\n", is_scalar($value) ? (string) $value : ''));
    }

    /**
     * The screenshots of an upload, checked to be what they say: a few images, each small enough.
     *
     * @param list<array{bytes:string,name:string}> $screenshots
     * @return list<array{bytes:string,name:string,mime:string,extension:string}>
     */
    private static function screenshots(PDO $db, array $screenshots): array
    {
        if (!$screenshots) return [];
        if (!self::attachable($db)) throw new RuntimeException('Jalankan migrasi plugin hingga versi 23 di System → Plugins untuk melampirkan tangkapan layar.');
        if (count($screenshots) > self::MAX_SCREENSHOTS) throw new RuntimeException('Lampirkan paling banyak ' . self::MAX_SCREENSHOTS . ' tangkapan layar.');
        $checked = [];
        foreach ($screenshots as $screenshot) {
            $bytes = (string) ($screenshot['bytes'] ?? '');
            if (strlen($bytes) > self::MAX_SCREENSHOT_BYTES) throw new RuntimeException('Tiap tangkapan layar paling besar 5 MB.');
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!isset(self::SCREENSHOT_TYPES[$mime]) || @getimagesizefromstring($bytes) === false) throw new RuntimeException('Lampirkan gambar PNG, JPG, atau WebP.');
            $name = mb_substr(trim(basename(str_replace('\\', '/', (string) ($screenshot['name'] ?? '')))), 0, 200);
            $checked[] = ['bytes' => $bytes, 'name' => $name !== '' ? $name : 'tangkapan-layar.' . self::SCREENSHOT_TYPES[$mime], 'mime' => $mime, 'extension' => self::SCREENSHOT_TYPES[$mime]];
        }
        return $checked;
    }

    /**
     * The screenshots of a form, as submit() takes them: each checked to be an upload of this request.
     *
     * @return list<array{bytes:string,name:string}>
     */
    public static function uploaded(array $files): array
    {
        $names = is_array($files['name'] ?? null) ? $files['name'] : [];
        $taken = [];
        foreach (array_keys($names) as $n) {
            $error = (int) ($files['error'][$n] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('Tiap tangkapan layar paling besar 5 MB.');
            $path = $files['tmp_name'][$n] ?? null;
            if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)) throw new RuntimeException('Tangkapan layar tidak terunggah. Coba lagi.');
            if ((int) filesize($path) > self::MAX_SCREENSHOT_BYTES) throw new RuntimeException('Tiap tangkapan layar paling besar 5 MB.');
            $taken[] = ['bytes' => (string) file_get_contents($path), 'name' => (string) $names[$n]];
        }
        return $taken;
    }

    /** @param array{bytes:string,name:string,mime:string,extension:string} $screenshot */
    private static function keepScreenshot(PDO $db, int $feedbackId, array $screenshot, string $now): void
    {
        $directory = self::directory();
        PhotoStorage::protect($directory);
        $filename = bin2hex(random_bytes(16)) . '.' . $screenshot['extension'];
        if (file_put_contents($directory . '/' . $filename, $screenshot['bytes'], LOCK_EX) !== strlen($screenshot['bytes'])) {
            throw new RuntimeException('Tangkapan layar tidak dapat disimpan.');
        }
        $db->prepare('INSERT INTO inventory_feedback_attachments (feedback_id, filename, name, mime, size, state, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$feedbackId, $filename, $screenshot['name'], $screenshot['mime'], strlen($screenshot['bytes']), 'pending', $now]);
    }

    /**
     * Sends the screenshots still waiting, of one piece or of all sent ones, a few at a time.
     * @return int how many were sent
     */
    private static function sendScreenshots(PDO $db, ?int $feedbackId = null): int
    {
        if (!self::attachable($db)) return 0;
        $query = $db->prepare("SELECT a.id, a.filename, a.name, a.mime, f.panel_id, f.token FROM inventory_feedback_attachments a JOIN inventory_feedback f ON f.id = a.feedback_id WHERE a.state = 'pending' AND f.panel_id IS NOT NULL" . ($feedbackId === null ? '' : ' AND f.id = ?') . ' ORDER BY a.id LIMIT ' . (self::MAX_FLUSH * self::MAX_SCREENSHOTS));
        $query->execute($feedbackId === null ? [] : [$feedbackId]);
        $sent = 0;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result = self::sendScreenshot($db, $row);
            if ($result === 'sent') $sent++;
            // Klaras cannot be reached: the rest would wait too.
            if ($result === 'waiting') break;
        }
        return $sent;
    }

    /** 'sent'; 'refused' when Klaras turned it down or its file is gone; 'waiting' when Klaras could not be reached. */
    private static function sendScreenshot(PDO $db, array $row): string
    {
        $done = static function (string $state, ?int $panelId) use ($db, $row): string {
            $db->prepare('UPDATE inventory_feedback_attachments SET state = ?, panel_attachment_id = ? WHERE id = ?')->execute([$state, $panelId, (int) $row['id']]);
            // Klaras has it, or never will: the copy here goes either way.
            if (preg_match('/\A[a-f0-9]{32}\.(png|jpg|webp)\z/', (string) $row['filename'])) @unlink(self::directory() . '/' . $row['filename']);
            return $state;
        };
        $path = self::directory() . '/' . $row['filename'];
        if (!preg_match('/\A[a-f0-9]{32}\.(png|jpg|webp)\z/', (string) $row['filename']) || is_link($path) || !is_file($path)) return $done('refused', null);

        $answer = self::postFile(self::endpoint('/attachments'), [
            'install_id' => Telemetry::state($db)['install_id'],
            'id' => (string) $row['panel_id'],
            'token' => (string) $row['token'],
        ], $path, (string) $row['mime'], (string) $row['name']);
        if (is_int($answer['data']['id'] ?? null)) return $done('sent', $answer['data']['id']);
        if (is_array($answer['error'] ?? null)) return $done('refused', null);
        return 'waiting';
    }

    /** Whether migration 23 has run: screenshots with feedback. */
    public static function attachable(PDO $db): bool
    {
        try {
            $db->query('SELECT 1 FROM inventory_feedback_attachments LIMIT 0');
            return true;
        } catch (\PDOException $error) {
            return false;
        }
    }

    private static function directory(): string
    {
        if (is_string(self::$directory) && self::$directory !== '') return rtrim(self::$directory, '/');
        return SB . 'images/inventaris-barang/masukan';
    }

    /**
     * The librarian answering in their own thread. Kept here and sent at once; it waits, like the
     * feedback, when Klaras cannot be reached. A thread Klaras has closed refuses the answer.
     *
     * @return array<string,mixed> the piece as list() shows it
     */
    public static function answer(PDO $db, array $input, int $uid, string $now): array
    {
        return self::unicode($db, static function () use ($db, $input, $uid, $now): array {
            return self::answerNow($db, $input, $uid, $now);
        });
    }

    /** @return array<string,mixed> */
    private static function answerNow(PDO $db, array $input, int $uid, string $now): array
    {
        if (!self::threaded($db)) throw new RuntimeException('Jalankan migrasi plugin hingga versi 22 di System → Plugins untuk membalas.');
        $id = is_scalar($input['feedback_id'] ?? null) ? (int) $input['feedback_id'] : 0;
        $message = trim(str_replace("\r\n", "\n", is_scalar($input['message'] ?? null) ? (string) $input['message'] : ''));
        if (mb_strlen($message) < 2) throw new RuntimeException('Tulis balasan Anda.');
        if (mb_strlen($message) > 5000) throw new RuntimeException('Balasan maksimal 5.000 karakter.');
        $query = $db->prepare('SELECT * FROM inventory_feedback WHERE id = ? AND user_id = ?');
        $query->execute([$id, $uid]);
        $feedback = $query->fetch(PDO::FETCH_ASSOC);
        if (!$feedback) throw new RuntimeException('Masukan tidak ditemukan.');
        if ($feedback['panel_id'] === null) throw new RuntimeException('Masukan ini belum terkirim ke Klaras. Balas setelah terkirim.');
        if ((int) $feedback['can_reply'] !== 1) throw new RuntimeException('Percakapan ini sudah ditutup. Kirim masukan baru bila masih ada kendala.');

        $db->prepare('INSERT INTO inventory_feedback_replies (feedback_id, panel_reply_id, kind, message, replied_at, received_at) VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$id, 'sender', $message, $now, $now]);
        $reply = ['id' => (int) $db->lastInsertId(), 'message' => $message, 'feedback_id' => $id, 'panel_id' => $feedback['panel_id'], 'token' => $feedback['token']];
        if (self::sendAnswer($db, $reply, false) === 'closed') {
            throw new RuntimeException('Percakapan ini sudah ditutup. Kirim masukan baru bila masih ada kendala.');
        }

        foreach (self::listNow($db, $uid, 50) as $piece) {
            if ($piece['id'] === $id) return $piece;
        }
        throw new RuntimeException('Masukan tidak ditemukan.');
    }

    /**
     * Sends one answer. 'sent', 'waiting' when Klaras could not be reached, or 'closed' when Klaras
     * refused it because the thread has closed: then an answer just written is taken back (the
     * librarian still has it on screen), and one that had been waiting is kept, marked refused.
     *
     * @param array{id:int,message:string,feedback_id:int,panel_id:string,token:string} $reply
     */
    private static function sendAnswer(PDO $db, array $reply, bool $waited): string
    {
        $answer = self::post(self::endpoint('/replies'), [
            'install_id' => Telemetry::state($db)['install_id'],
            'id' => (string) $reply['panel_id'],
            'token' => (string) $reply['token'],
            'message' => (string) $reply['message'],
        ]);
        if (is_int($answer['data']['id'] ?? null)) {
            $db->prepare('UPDATE inventory_feedback_replies SET panel_reply_id = ? WHERE id = ?')->execute([$answer['data']['id'], $reply['id']]);
            $status = (string) ($answer['data']['status'] ?? '');
            $db->prepare('UPDATE inventory_feedback SET awaiting_reply = 0, status = ? WHERE id = ?')
                ->execute([isset(self::STATUSES[$status]) && $status !== 'pending' ? $status : 'reviewing', $reply['feedback_id']]);
            return 'sent';
        }
        if (($answer['error']['code'] ?? '') === 'feedback_closed') {
            $db->prepare('UPDATE inventory_feedback SET can_reply = 0, awaiting_reply = 0 WHERE id = ?')->execute([$reply['feedback_id']]);
            $db->prepare($waited ? "UPDATE inventory_feedback_replies SET kind = 'refused' WHERE id = ?" : 'DELETE FROM inventory_feedback_replies WHERE id = ?')->execute([$reply['id']]);
            return 'closed';
        }
        return 'waiting';
    }

    /** Whether migration 22 has run: threads, with answers from the librarian. */
    private static function threaded(PDO $db): bool
    {
        try {
            $db->query('SELECT can_reply, awaiting_reply FROM inventory_feedback LIMIT 0');
            return true;
        } catch (\PDOException $error) {
            return false;
        }
    }

    /** Feedback, an answer in a thread, or a screenshot still waiting to be sent. */
    private static function waiting(PDO $db): bool
    {
        return (bool) $db->query('SELECT 1 FROM inventory_feedback WHERE panel_id IS NULL LIMIT 1')->fetchColumn()
            || (bool) $db->query("SELECT 1 FROM inventory_feedback_replies WHERE panel_reply_id IS NULL AND kind = 'sender' LIMIT 1")->fetchColumn()
            || (self::attachable($db) && (bool) $db->query("SELECT 1 FROM inventory_feedback_attachments WHERE state = 'pending' LIMIT 1")->fetchColumn());
    }

    /**
     * Whether there is anything to do after a page: feedback or answers waiting to be sent (tried
     * at most hourly while Klaras cannot be reached) or replies to ask for (every six hours). Cheap:
     * a few small queries, and never an error before the migration has run.
     */
    public static function due(PDO $db): bool
    {
        try {
            $waiting = self::waiting($db);
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
            if (self::waiting($db) && time() - (int) self::setting($db, self::TRIED) >= self::RETRY) {
                self::store($db, self::TRIED, (string) time());
                self::flush($db);
            }
            self::refresh($db);
        } catch (\Throwable $error) {
        }
    }

    /** Sends what is still waiting, feedback and then answers, a few at a time. @return int how many were sent */
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
        // Screenshots follow the feedback they belong to, once it has reached Klaras.
        $sent += self::sendScreenshots($db);
        if (!self::threaded($db)) return $sent;
        // Answers in threads whose feedback has reached Klaras, oldest first, so they arrive in order.
        $answers = $db->query("SELECT r.id, r.message, f.id AS feedback_id, f.panel_id, f.token FROM inventory_feedback_replies r JOIN inventory_feedback f ON f.id = r.feedback_id WHERE r.panel_reply_id IS NULL AND r.kind = 'sender' AND f.panel_id IS NOT NULL ORDER BY r.id LIMIT " . self::MAX_FLUSH)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($answers as $answer) {
            $result = self::sendAnswer($db, ['id' => (int) $answer['id'], 'message' => (string) $answer['message'], 'feedback_id' => (int) $answer['feedback_id'], 'panel_id' => (string) $answer['panel_id'], 'token' => (string) $answer['token']], true);
            if ($result === 'sent') $sent++;
            // Klaras still cannot be reached: the rest would wait too.
            if ($result === 'waiting') break;
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
        $threaded = self::threaded($db);
        $status = $db->prepare($threaded
            ? 'UPDATE inventory_feedback SET status = ?, issue_url = ?, awaiting_reply = ?, can_reply = ? WHERE id = ?'
            : 'UPDATE inventory_feedback SET status = ?, issue_url = ? WHERE id = ?');
        // Checked, then added: a reply is kept once, and the SQL stays plain enough for the tests' SQLite.
        $known = $db->prepare('SELECT 1 FROM inventory_feedback_replies WHERE feedback_id = ? AND panel_reply_id = ?');
        $reply = $db->prepare('INSERT INTO inventory_feedback_replies (feedback_id, panel_reply_id, kind, message, replied_at, received_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($answer['data'] as $piece) {
            $id = $ids[(string) ($piece['id'] ?? '')] ?? null;
            if ($id === null || !isset(self::STATUSES[(string) ($piece['status'] ?? '')])) continue;
            $url = is_string($piece['issue_url'] ?? null) && preg_match('#\Ahttps://github\.com/[\w.-]+/[\w.-]+/issues/\d+\z#', $piece['issue_url']) ? $piece['issue_url'] : null;
            $status->execute($threaded
                ? [(string) $piece['status'], $url, empty($piece['awaiting_reply']) ? 0 : 1, ($piece['can_reply'] ?? true) === false ? 0 : 1, $id]
                : [(string) $piece['status'], $url, $id]);
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
    public static function list(PDO $db, int $uid, int $limit = 50): array
    {
        return self::unicode($db, static function () use ($db, $uid, $limit): array { return self::listNow($db, $uid, $limit); });
    }

    /** @return list<array<string,mixed>> */
    private static function listNow(PDO $db, int $uid, int $limit): array
    {
        $query = $db->prepare('SELECT f.*, u.realname FROM inventory_feedback f LEFT JOIN user u ON u.user_id = f.user_id WHERE f.user_id = ? ORDER BY f.id DESC LIMIT ' . max(1, min(200, $limit)));
        $query->execute([$uid]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $ids = array_map('intval', array_column($rows, 'id'));
        $query = $db->prepare('SELECT * FROM inventory_feedback_replies WHERE feedback_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY replied_at, id');
        $query->execute($ids);
        $replies = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $reply) $replies[(int) $reply['feedback_id']][] = $reply;
        $screenshots = [];
        if (self::attachable($db)) {
            $query = $db->prepare('SELECT feedback_id, name, state FROM inventory_feedback_attachments WHERE feedback_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
            $query->execute($ids);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $screenshot) $screenshots[(int) $screenshot['feedback_id']][] = ['name' => (string) $screenshot['name'], 'state' => (string) $screenshot['state']];
        }

        return array_map(static function (array $row) use ($replies, $screenshots): array {
            $own = $replies[(int) $row['id']] ?? [];
            $seen = (string) ($row['seen_at'] ?? '');
            $awaiting = !empty($row['awaiting_reply']) && !in_array($row['status'], ['done', 'declined'], true);
            return [
                'id' => (int) $row['id'],
                'kind' => ['key' => (string) $row['kind'], 'label' => self::KINDS[$row['kind']] ?? (string) $row['kind']],
                'message' => (string) $row['message'],
                'page' => (string) $row['page'],
                'author' => (string) ($row['realname'] ?? ''),
                'contact' => (bool) $row['contact'],
                'status' => $awaiting ? ['key' => 'awaiting', 'label' => self::AWAITING] : ['key' => (string) $row['status'], 'label' => self::STATUSES[$row['status']] ?? (string) $row['status']],
                // Before migration 22 there is no can_reply column, and so no answering.
                'can_reply' => $row['panel_id'] !== null && (int) ($row['can_reply'] ?? 0) === 1,
                'issue_url' => $row['issue_url'] === null ? null : (string) $row['issue_url'],
                'created_at' => (string) $row['created_at'],
                // Names and whether Klaras has them; the files themselves are not kept here once sent.
                'screenshots' => $screenshots[(int) $row['id']] ?? [],
                'replies' => array_map(static function (array $reply) use ($seen): array {
                    $mine = in_array((string) $reply['kind'], self::OWN, true);
                    return [
                        'message' => (string) $reply['message'],
                        'kind' => (string) $reply['kind'],
                        'replied_at' => (string) $reply['replied_at'],
                        'from_sender' => $mine,
                        'pending' => $reply['kind'] === 'sender' && $reply['panel_reply_id'] === null,
                        'unread' => !$mine && ($seen === '' || (string) $reply['received_at'] > $seen),
                    ];
                }, $own),
            ];
        }, $rows);
    }

    /** Klaras's replies to what the staff member $uid sent that they have not opened their history to read. */
    public static function unread(PDO $db, int $uid): int
    {
        $query = $db->prepare("SELECT COUNT(*) FROM inventory_feedback_replies r JOIN inventory_feedback f ON f.id = r.feedback_id WHERE f.user_id = ? AND r.kind NOT IN ('sender', 'refused') AND (f.seen_at IS NULL OR r.received_at > f.seen_at)");
        $query->execute([$uid]);
        return (int) $query->fetchColumn();
    }

    /** The staff member $uid has read the replies to what they sent. */
    public static function markSeen(PDO $db, int $uid, string $now): void
    {
        $db->prepare('UPDATE inventory_feedback SET seen_at = ? WHERE user_id = ?')->execute([$now, $uid]);
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

    /**
     * @return array<string,mixed>|null the decoded answer of a 2xx, or of a 4xx refusal Klaras explains
     *                                  ({"error": {"code", "message"}}); null when Klaras could not be reached
     */
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
        return self::answerOf($curl);
    }

    /**
     * Sends a file with a few fields, as multipart. Answers as post() does. Tests' transport gets the
     * fields and, under 'file', where the file is and what it is.
     *
     * @return array<string,mixed>|null
     */
    private static function postFile(string $url, array $fields, string $path, string $mime, string $name): ?array
    {
        if (is_callable(self::$transport)) return call_user_func(self::$transport, $url, $fields + ['file' => ['path' => $path, 'mime' => $mime, 'name' => $name]]);
        if (!function_exists('curl_init')) return null;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields + ['file' => new \CURLFile($path, $mime, $name)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: klaras-inven/' . UpdateCheck::plugin()['version']],
        ]);
        return self::answerOf($curl);
    }

    /** @return array<string,mixed>|null */
    private static function answerOf($curl): ?array
    {
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($decoded)) return null;
        if ($status >= 200 && $status < 300) return $decoded;
        return $status >= 400 && $status < 500 && is_array($decoded['error'] ?? null) ? $decoded : null;
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
