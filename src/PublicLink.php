<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

/**
 * Signed public links for item labels. A link carries the item id and a short HMAC so the public
 * page opens only for printed labels and cannot be walked by guessing sequential ids.
 * The key is generated once and kept in SLiMS's `setting` table (serialized, like other settings).
 */
final class PublicLink
{
    public const SETTING = 'inventory_label_secret';
    public const PAGE = 'info_barang';
    private const TOKEN_LENGTH = 12;

    private static ?string $secret = null;

    public static function secret(\PDO $db): string
    {
        if (self::$secret !== null) return self::$secret;
        $read = $db->prepare('SELECT setting_value FROM setting WHERE setting_name = ?');
        $read->execute([self::SETTING]);
        $value = $read->fetchColumn();
        $secret = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : null;
        if (!is_string($secret) || strlen($secret) < 32) {
            // INSERT IGNORE keeps the first key if two requests race; both then re-read the stored one.
            $db->prepare('INSERT IGNORE INTO setting (setting_name, setting_value) VALUES (?, ?)')
                ->execute([self::SETTING, serialize(bin2hex(random_bytes(32)))]);
            $read->execute([self::SETTING]);
            $secret = @unserialize((string) $read->fetchColumn(), ['allowed_classes' => false]);
            if (!is_string($secret) || strlen($secret) < 32) throw new \RuntimeException('Kunci tautan publik tidak dapat dibuat.');
        }
        return self::$secret = $secret;
    }

    public static function token(\PDO $db, int $itemId): string
    {
        return substr(hash_hmac('sha256', 'item:' . $itemId, self::secret($db)), 0, self::TOKEN_LENGTH);
    }

    public static function verify(\PDO $db, int $itemId, string $token): bool
    {
        return $itemId > 0 && strlen($token) === self::TOKEN_LENGTH && hash_equals(self::token($db, $itemId), $token);
    }

    /** Query string for the OPAC page, appended to the site's index.php URL. */
    public static function query(\PDO $db, int $itemId, array $extra = []): string
    {
        return http_build_query(['p' => self::PAGE, 'i' => $itemId, 't' => self::token($db, $itemId)] + $extra);
    }
}
