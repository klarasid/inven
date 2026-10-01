<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\Request;
use SlimsConnect\Plugin;
use SlimsConnect\Support\Settings;

/**
 * What must hold before any InvenSync request is served: the library's administrator switched
 * the app on, the request came over https, SLiMS Connect is linked to Klaras Panel, and the
 * library's plan includes InvenSync.
 */
final class Guard
{
    /** In SLiMS's setting table, so it is also in $sysconf when the plugin file loads. */
    public const SETTING = 'invensync_enabled';

    public static function enabled(PDO $db): bool
    {
        $statement = $db->prepare('SELECT setting_value FROM setting WHERE setting_name = ?');
        $statement->execute([self::SETTING]);
        $value = @unserialize((string) $statement->fetchColumn(), ['allowed_classes' => false]);

        return $value === '1' || $value === 1 || $value === true;
    }

    public static function setEnabled(PDO $db, bool $enabled): void
    {
        $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([self::SETTING, serialize($enabled ? '1' : '0')]);
    }

    public static function requireHttps(): bool
    {
        return Settings::bool(Settings::REQUIRE_HTTPS, !Plugin::isDevelopment());
    }

    public static function check(Request $request, PDO $db): void
    {
        if (!self::enabled($db)) {
            throw Failure::forbidden('invensync_disabled', 'Aplikasi InvenSync belum diizinkan di perpustakaan ini. Minta administrator SLiMS menyalakannya di Stock Take → Aplikasi InvenSync.');
        }
        if (!$request->isSecure() && self::requireHttps()) {
            throw Failure::forbidden('https_required', 'Perpustakaan ini perlu memakai https agar aplikasi bisa tersambung dengan aman.');
        }
        if (!Licence::linked()) {
            throw new ApiException('klaras_not_linked', 'SLiMS perpustakaan ini belum ditautkan ke Klaras Panel. Minta administrator SLiMS mengisi API key di SLiMS Connect.', 503);
        }
        if (!Licence::allows()) {
            throw Failure::forbidden('invensync_not_licensed', 'Paket perpustakaan ini di Klaras Panel belum mencakup Klaras InvenSync.');
        }
    }
}
