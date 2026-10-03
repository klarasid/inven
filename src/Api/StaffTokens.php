<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SlimsConnect\Auth\TokenService;
use SlimsConnect\Http\ApiException;

/**
 * Sessions of the Klaras InvenSync app (table inventory_api_sessions).
 *
 * Every time here is PHP's clock, never MySQL's NOW(): the two often sit in different time
 * zones on shared hosting, and mixing them would expire sessions early or late by hours.
 *
 * Tokens are "<prefix><selector>.<validator>": isa_ for access (one hour), isr_ for refresh
 * (30 days, only when the librarian chose "Ingat saya"). Only SHA-256 of the validator is
 * stored. A refresh token works once: using it rotates both tokens, and presenting the old
 * one again means it was copied, so the whole session is ended.
 *
 * A session also ends when the librarian's password changes, when the account is switched
 * off or loses Stock Take, and when an administrator revokes it in SLiMS.
 */
final class StaffTokens
{
    public const ACCESS_PREFIX = 'isa_';
    public const REFRESH_PREFIX = 'isr_';
    public const ACCESS_TTL_SECONDS = 3600;
    public const REFRESH_TTL_SECONDS = 2592000;
    private const TOUCH_EVERY_SECONDS = 300;

    public function __construct(private PDO $db) {}

    private function query(string $sql, array $args = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement;
    }

    /** @return array{selector: string, hash: string, plaintext: string} */
    private static function mint(): array
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));

        return ['selector' => $selector, 'hash' => hash('sha256', $validator), 'plaintext' => $selector . '.' . $validator];
    }

    public static function fingerprint(array $user): string
    {
        return hash('sha256', 'invensync|' . (string) $user['user_id'] . '|' . (string) $user['passwd']);
    }

    private static function at(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public function issue(array $user, string $deviceName, string $ip, bool $remember, string $kind = 'app'): array
    {
        $this->assertSeat((int) $user['user_id']);
        $now = time();
        $access = self::mint();
        $refresh = $remember ? self::mint() : null;
        // The kind column arrives with migration 13; phones keep signing in before it runs.
        $agent = $kind === AgentCodes::KIND;
        $this->query(
            'INSERT INTO inventory_api_sessions (user_id, access_selector, access_hash, access_expires_at, refresh_selector, refresh_hash, refresh_expires_at, password_fingerprint, device_name, ip, created_at, last_used_at' . ($agent ? ', kind' : '') . ')
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . ($agent ? ', ?' : '') . ')',
            array_merge([
                $user['user_id'], $access['selector'], $access['hash'], self::at($now + self::ACCESS_TTL_SECONDS),
                $refresh['selector'] ?? null, $refresh['hash'] ?? null, $refresh ? self::at($now + self::REFRESH_TTL_SECONDS) : null,
                self::fingerprint($user), mb_substr(trim($deviceName), 0, 100), mb_substr($ip, 0, 45), self::at($now), self::at($now),
            ], $agent ? [$kind] : [])
        );
        $this->prune();

        return self::pair($access['plaintext'], $refresh['plaintext'] ?? null);
    }

    /**
     * The plan may limit how many librarians use the app at once. Someone already signed in on
     * another device does not take a second seat.
     */
    private function assertSeat(int $userId): void
    {
        $limit = Licence::staffLimit();
        if ($limit === null) {
            return;
        }
        $active = $this->query(
            'SELECT DISTINCT user_id FROM inventory_api_sessions WHERE revoked_at IS NULL AND (access_expires_at > ? OR refresh_expires_at > ?)',
            [self::at(time()), self::at(time())]
        )->fetchAll(PDO::FETCH_COLUMN);
        $active = array_map('intval', $active);
        if (!in_array($userId, $active, true) && count($active) >= $limit) {
            throw Failure::forbidden('staff_limit_reached', "Paket perpustakaan ini mengizinkan $limit petugas memakai aplikasi sekaligus. Minta petugas lain keluar, atau naikkan paket di Klaras Panel.");
        }
    }

    /** @return array<string, mixed> */
    private static function pair(string $access, ?string $refresh): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => self::ACCESS_PREFIX . $access,
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'refresh_token' => $refresh === null ? null : self::REFRESH_PREFIX . $refresh,
            'refresh_expires_in' => $refresh === null ? null : self::REFRESH_TTL_SECONDS,
        ];
    }

    /**
     * The session and user behind an access token.
     *
     * @return array{session: array<string, mixed>, user: array<string, mixed>}
     */
    public function authenticate(?string $token): array
    {
        $parts = $token === null ? null : TokenService::parse($token, self::ACCESS_PREFIX);
        if ($parts === null) {
            throw Failure::unauthenticated('Masuk dulu untuk melanjutkan.');
        }
        $session = $this->query('SELECT * FROM inventory_api_sessions WHERE access_selector = ?', [$parts['selector']])->fetch(PDO::FETCH_ASSOC);
        if (!$session || !hash_equals((string) $session['access_hash'], hash('sha256', $parts['validator'])) || $session['revoked_at'] !== null) {
            throw Failure::unauthenticated();
        }
        if (strtotime((string) $session['access_expires_at']) <= time()) {
            throw new ApiException('token_expired', 'Sesi perlu diperbarui.', 401);
        }
        $user = $this->liveUser($session);
        if (time() - (int) strtotime((string) $session['last_used_at']) > self::TOUCH_EVERY_SECONDS) {
            $this->query('UPDATE inventory_api_sessions SET last_used_at = ? WHERE id = ?', [self::at(time()), $session['id']]);
        }

        return ['session' => $session, 'user' => $user];
    }

    /**
     * The account a session belongs to, if it may still use the app.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function liveUser(array $session): array
    {
        $user = $this->query('SELECT * FROM user WHERE user_id = ?', [$session['user_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$user || (string) ($user['is_active'] ?? '1') !== '1' || !hash_equals((string) $session['password_fingerprint'], self::fingerprint($user))) {
            $this->revoke((int) $session['id']);
            throw Failure::unauthenticated('Akun atau kata sandi Anda berubah. Masuk lagi untuk melanjutkan.');
        }

        return $user;
    }

    /**
     * New tokens for a refresh token, which stops working.
     *
     * @return array{tokens: array<string, mixed>, user: array<string, mixed>, session_id: int}
     */
    public function refresh(string $token, string $ip): array
    {
        $parts = TokenService::parse($token, self::REFRESH_PREFIX);
        if ($parts === null) {
            throw Failure::unauthenticated();
        }
        $this->db->beginTransaction();
        try {
            $session = $this->query('SELECT * FROM inventory_api_sessions WHERE refresh_selector = ? FOR UPDATE', [$parts['selector']])->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                // A token already rotated away, presented again: someone else holds a copy.
                $reused = $this->query('SELECT id FROM inventory_api_sessions WHERE previous_refresh_selector = ? AND revoked_at IS NULL', [$parts['selector']])->fetchColumn();
                if ($reused !== false) {
                    $this->query('UPDATE inventory_api_sessions SET revoked_at = ? WHERE id = ?', [self::at(time()), $reused]);
                    $this->db->commit();
                    throw new ApiException('refresh_reused', 'Sesi dihentikan demi keamanan karena dipakai di dua tempat. Masuk lagi.', 401);
                }
                throw Failure::unauthenticated();
            }
            if (!hash_equals((string) $session['refresh_hash'], hash('sha256', $parts['validator'])) || $session['revoked_at'] !== null
                || strtotime((string) $session['refresh_expires_at']) <= time()) {
                throw Failure::unauthenticated();
            }
            $user = $this->liveUser($session);
            $access = self::mint();
            $refresh = self::mint();
            $now = time();
            $this->query(
                'UPDATE inventory_api_sessions SET access_selector = ?, access_hash = ?, access_expires_at = ?, previous_refresh_selector = refresh_selector,
                 refresh_selector = ?, refresh_hash = ?, refresh_expires_at = ?, ip = ?, last_used_at = ? WHERE id = ?',
                [$access['selector'], $access['hash'], self::at($now + self::ACCESS_TTL_SECONDS), $refresh['selector'], $refresh['hash'], self::at($now + self::REFRESH_TTL_SECONDS), mb_substr($ip, 0, 45), self::at($now), $session['id']]
            );
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return ['tokens' => self::pair($access['plaintext'], $refresh['plaintext']), 'user' => $user, 'session_id' => (int) $session['id'], 'kind' => (string) ($session['kind'] ?? 'app')];
    }

    public function revoke(int $sessionId): void
    {
        $this->query('UPDATE inventory_api_sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [self::at(time()), $sessionId]);
    }

    /**
     * Sessions still able to reach the API, newest first, for the SLiMS admin page.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return $this->query(
            'SELECT s.*, u.realname, u.username
             FROM inventory_api_sessions s LEFT JOIN user u ON u.user_id = s.user_id
             WHERE s.revoked_at IS NULL AND (s.access_expires_at > ? OR s.refresh_expires_at > ?)
             ORDER BY s.last_used_at DESC LIMIT 200',
            [self::at(time()), self::at(time())]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Forget sessions that ended more than 30 days ago. */
    private function prune(): void
    {
        $this->query(
            'DELETE FROM inventory_api_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?)
             OR (access_expires_at < ? AND (refresh_expires_at IS NULL OR refresh_expires_at < ?)) LIMIT 100',
            array_fill(0, 3, self::at(time() - 30 * 86400))
        );
    }
}
