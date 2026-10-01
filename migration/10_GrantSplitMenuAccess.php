<?php

use SLiMS\DB;

/**
 * Perangkat Lunak and Gedung & Jaringan used to be tabs of Rekap Sarpras, and Pengaturan Cetak
 * used to be two buttons on Laporan. SLiMS grants plugin menus per file, so a group that could
 * open the old page gets the menus split from it; nobody gains access to something new.
 * Staff see the menus after signing in again.
 */
class GrantSplitMenuAccess extends \SLiMS\Migration\Migration
{
    /** Menu file that held the feature => menu files it now lives in. */
    private const SPLITS = [
        'sarpras.php' => ['software.php', 'facility.php'],
        'report.php' => ['print-settings.php'],
    ];

    /** The id SLiMS gives a plugin menu: Plugins::registerMenu(). */
    private static function id(string $file): string
    {
        return md5((string) realpath(dirname(__DIR__) . '/' . $file));
    }

    private static function hasMenus(\PDO $db): bool
    {
        $query = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $query->execute(['group_access', 'menus']);
        return (bool) $query->fetchColumn();
    }

    /** Rewrites the menu list of every group that has one, wherever $change alters it. */
    private static function each(callable $change): void
    {
        $db = DB::getInstance();
        if (!self::hasMenus($db)) return;
        $update = $db->prepare('UPDATE group_access SET menus = ? WHERE group_id = ? AND module_id = ?');
        foreach ($db->query('SELECT group_id, module_id, menus FROM group_access WHERE menus IS NOT NULL')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $menus = json_decode((string) $row['menus'], true);
            if (!is_array($menus)) continue;
            $changed = array_values(array_unique($change($menus)));
            if ($changed !== array_values($menus)) $update->execute([json_encode($changed), $row['group_id'], $row['module_id']]);
        }
    }

    public function up()
    {
        self::each(static function (array $menus): array {
            foreach (self::SPLITS as $from => $files) {
                if (in_array(self::id($from), $menus, true)) $menus = array_merge($menus, array_map([self::class, 'id'], $files));
            }
            return $menus;
        });
    }

    public function down()
    {
        $added = array_map([self::class, 'id'], array_merge(...array_values(self::SPLITS)));
        self::each(static fn(array $menus): array => array_filter($menus, static fn($menu) => !in_array($menu, $added, true)));
    }
}
