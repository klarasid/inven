<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SlimsConnect\Auth\PasswordVariants;
use SlimsConnect\Auth\RateLimiter;
use SlimsConnect\Http\ApiException;

/**
 * Signs a librarian in with their SLiMS username and password, as SLiMS's own staff login
 * does, including the one-time code when the account has two-step verification.
 *
 * Refuses the same way whether the username is unknown or the password wrong, and spends the
 * same time hashing in both cases, so the answer does not reveal which accounts exist.
 */
final class StaffAuthenticator
{
    private const ATTEMPTS_PER_USER = 5;
    private const ATTEMPTS_PER_IP = 20;
    private const WINDOW_SECONDS = 900;
    private const BLOCK_SECONDS = 900;
    /** A bcrypt hash of nothing in particular, verified when the user does not exist. */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(private PDO $db, private RateLimiter $limiter = new RateLimiter()) {}

    /**
     * @return array<string, mixed> The user row, password upgraded to bcrypt if it was MD5.
     */
    public function attempt(string $username, string $password, ?string $otp, string $ip): array
    {
        $userKey = 'invensync-login-user:' . mb_strtolower(trim($username));
        $ipKey = 'invensync-login-ip:' . $ip;
        $this->limiter->ensureAvailable($userKey);
        $this->limiter->ensureAvailable($ipKey);

        $statement = $this->db->prepare('SELECT * FROM user WHERE username = ? LIMIT 1');
        $statement->execute([trim($username)]);
        $user = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
        }
        if ($user === null || !$this->passwordMatches($user, $password)) {
            $this->limiter->hit($userKey, self::ATTEMPTS_PER_USER, self::WINDOW_SECONDS, self::BLOCK_SECONDS);
            $this->limiter->hit($ipKey, self::ATTEMPTS_PER_IP, self::WINDOW_SECONDS, self::BLOCK_SECONDS);
            throw new ApiException('invalid_credentials', 'Nama pengguna atau kata sandi salah.', 401);
        }

        $secret = trim((string) ($user['2fa'] ?? ''));
        if ($secret !== '') {
            if ($otp === null || trim($otp) === '') {
                throw new ApiException('otp_required', 'Masukkan kode verifikasi dari aplikasi autentikator Anda.', 401, ['otp' => true]);
            }
            if (!$this->otpMatches($secret, trim($otp))) {
                $this->limiter->hit($userKey, self::ATTEMPTS_PER_USER, self::WINDOW_SECONDS, self::BLOCK_SECONDS);
                throw new ApiException('invalid_otp', 'Kode verifikasi salah atau sudah kedaluwarsa. Coba kode yang baru.', 401, ['otp' => true]);
            }
        }

        if ((string) ($user['is_active'] ?? '1') !== '1') {
            throw Failure::forbidden('account_inactive', 'Akun SLiMS ini tidak aktif. Hubungi administrator SLiMS di perpustakaan Anda.');
        }

        $this->limiter->clear($userKey);

        return $user;
    }

    /** @param array<string, mixed> $user */
    private function passwordMatches(array &$user, string $password): bool
    {
        $stored = (string) ($user['passwd'] ?? '');
        foreach (PasswordVariants::of($password) as $candidate) {
            if (password_verify($candidate, $stored)) {
                return true;
            }
            // Accounts created before SLiMS moved to bcrypt. SLiMS upgrades these on the next
            // sign-in too; doing it here keeps the MD5 hash from living on.
            if (strlen($stored) === 32 && hash_equals($stored, md5($candidate))) {
                $user['passwd'] = password_hash($candidate, PASSWORD_BCRYPT);
                $this->db->prepare('UPDATE user SET passwd = ?, last_update = CURDATE() WHERE user_id = ?')->execute([$user['passwd'], $user['user_id']]);
                return true;
            }
        }
        return false;
    }

    private function otpMatches(string $secret, string $code): bool
    {
        if (preg_match('/^\d{6}$/D', $code) !== 1 || !class_exists(\OTPHP\TOTP::class)) {
            return false;
        }
        try {
            return \OTPHP\TOTP::createFromSecret($secret)->verify($code, null, 10);
        } catch (\Throwable $error) {
            error_log('[invensync] 2FA check failed: ' . $error->getMessage());
            return false;
        }
    }
}
