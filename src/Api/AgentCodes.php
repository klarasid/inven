<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use RuntimeException;

/**
 * Agent AI connections: an AI app (Claude and the like) reaching this SLiMS through Klaras
 * Panel's MCP server, acting as the librarian who allowed it.
 *
 * The librarian allows it on a page of this SLiMS while signed in, so their password never leaves
 * it. That page hands Klaras Panel a code that works once, for five minutes, and only together
 * with the PKCE verifier the Panel holds; the Panel trades it for an InvenSync session of kind
 * "agent", listed and revocable on the Aplikasi InvenSync page like a phone.
 *
 * The administrator switches agents on or off apart from the app. Plain SQL and PHP's clock, so
 * the tests run it on SQLite.
 */
final class AgentCodes
{
    public const SETTING = 'invensync_agents';
    /** Where Klaras Panel takes the code back. Any other address is refused. */
    public const CALLBACK_PATH = '/oauth/agent/callback';
    public const KIND = 'agent';
    private const TTL_SECONDS = 300;

    private static function read(PDO $db): ?string
    {
        $statement = $db->prepare('SELECT setting_value FROM setting WHERE setting_name = ?');
        $statement->execute([self::SETTING]);
        $value = @unserialize((string) $statement->fetchColumn(), ['allowed_classes' => false]);

        return is_string($value) ? $value : null;
    }

    public static function disabled(): \SlimsConnect\Http\ApiException
    {
        return Failure::forbidden('agents_disabled', 'Agent AI belum diizinkan di perpustakaan ini. Minta administrator SLiMS menyalakannya di Stock Take → Aplikasi InvenSync.');
    }

    /** The consent page, on the Aplikasi InvenSync menu of this SLiMS. */
    public static function authorizeUrl(): string
    {
        $origin = \SLiMS\Plugins\Inventory\PublicLink::scheme() . '://' . \SLiMS\Plugins\Inventory\PublicLink::host();

        return $origin . \SLiMS\Plugins\Inventory\Workspace::endpoint('app.php', ['agent' => 'authorize']);
    }

    public static function enabled(PDO $db): bool
    {
        return self::read($db) === '1';
    }

    /** Switching agents off also ends every agent session. */
    public static function setEnabled(PDO $db, bool $enabled, string $now): void
    {
        $value = serialize($enabled ? '1' : '0');
        $update = $db->prepare('UPDATE setting SET setting_value = ? WHERE setting_name = ?');
        $update->execute([$value, self::SETTING]);
        if ($update->rowCount() === 0 && self::read($db) === null) {
            $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?)')->execute([self::SETTING, $value]);
        }
        if (!$enabled) {
            $db->prepare('UPDATE inventory_api_sessions SET revoked_at = ? WHERE kind = ? AND revoked_at IS NULL')->execute([$now, self::KIND]);
        }
    }

    /** The Panel's callback, exactly: the address comes from SLiMS Connect, never from the request. */
    public static function redirectAllowed(string $redirectUri, string $panelUrl): bool
    {
        $panelUrl = rtrim(trim($panelUrl), '/');

        return $panelUrl !== '' && str_starts_with($panelUrl, 'https://') && hash_equals($panelUrl . self::CALLBACK_PATH, $redirectUri);
    }

    /** A PKCE S256 challenge: base64url of a SHA-256 digest. */
    public static function validChallenge(string $challenge): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $challenge) === 1;
    }

    /** How the app is named on the consent page and in the sessions list. */
    public static function clientName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 60) : substr($name, 0, 60);

        return $name !== '' ? $name : 'Agent AI';
    }

    /** A code for the librarian who allowed the app. @return string the code, shown once */
    public static function create(PDO $db, int $userId, string $client, string $challenge, string $redirectUri, int $now): string
    {
        if (!self::validChallenge($challenge)) {
            throw new RuntimeException('Permintaan tidak lengkap. Mulai lagi dari aplikasi AI Anda.');
        }
        $db->prepare('DELETE FROM inventory_api_codes WHERE created_at < ?')->execute([date('Y-m-d H:i:s', $now - 86400)]);
        $code = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO inventory_api_codes (code_hash, user_id, client_name, code_challenge, redirect_uri, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $code), $userId, self::clientName($client), $challenge, $redirectUri, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + self::TTL_SECONDS)]);

        return $code;
    }

    /**
     * The librarian and app behind a code, which stops working on its first use, right or wrong.
     *
     * @return array{user_id: int, client: string}
     */
    public static function redeem(PDO $db, string $code, string $verifier, string $redirectUri, int $now): array
    {
        $refused = new RuntimeException('Kode persetujuan tidak berlaku. Hubungkan lagi dari aplikasi AI Anda.');
        if (preg_match('/\A[a-f0-9]{64}\z/', $code) !== 1 || preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier) !== 1) {
            throw $refused;
        }
        $hash = hash('sha256', $code);
        $statement = $db->prepare('SELECT * FROM inventory_api_codes WHERE code_hash = ?');
        $statement->execute([$hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw $refused;
        }
        $use = $db->prepare('UPDATE inventory_api_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL');
        $use->execute([date('Y-m-d H:i:s', $now), $hash]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if ($use->rowCount() !== 1 || strtotime((string) $row['expires_at']) <= $now
            || !hash_equals((string) $row['code_challenge'], $challenge) || !hash_equals((string) $row['redirect_uri'], $redirectUri)) {
            throw $refused;
        }

        return ['user_id' => (int) $row['user_id'], 'client' => (string) $row['client_name']];
    }
}
