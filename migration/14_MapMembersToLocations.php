<?php

use SLiMS\DB;

/**
 * Sivitas per location (Sivitas): where Institusi values and member types are placed, and the
 * merges of mistyped Institusi values, kept so each can be undone. Sivitas used to be typed in on
 * Gedung & Jaringan, so groups that could open that menu get Sivitas per Lokasi too (as in
 * GrantSplitMenuAccess); staff see it after signing in again. Safe to run again.
 */
class MapMembersToLocations extends \SLiMS\Migration\Migration
{
    /** The id SLiMS gives a plugin menu: Plugins::registerMenu(). */
    private static function menu(string $file): string
    {
        return md5((string) realpath(dirname(__DIR__) . '/' . $file));
    }

    /** Rewrites the menu list of every group that has one, wherever $change alters it. */
    private static function eachGroup(\PDO $db, callable $change): void
    {
        $query = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $query->execute(['group_access', 'menus']);
        if (!$query->fetchColumn()) return;
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
        $db = DB::getInstance();
        self::eachGroup($db, static fn(array $menus): array => in_array(self::menu('facility.php'), $menus, true) ? [...$menus, self::menu('sivitas.php')] : $menus);
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_member_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            basis VARCHAR(12) NOT NULL,
            value_key VARCHAR(100) NOT NULL,
            label VARCHAR(100) NOT NULL,
            location_code VARCHAR(3) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY inventory_member_locations_value (basis, value_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        // Each changed member with the spelling it had, so an undo restores exactly that.
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_member_fixes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_values TEXT NOT NULL,
            to_value VARCHAR(100) NOT NULL,
            members MEDIUMTEXT NOT NULL,
            member_count INT NOT NULL,
            user_id INT NULL,
            created_at DATETIME NOT NULL,
            undone_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        $db = DB::getInstance();
        self::eachGroup($db, static fn(array $menus): array => array_filter($menus, static fn($menu) => $menu !== self::menu('sivitas.php')));
        $db->exec('DROP TABLE IF EXISTS inventory_member_fixes');
        $db->exec('DROP TABLE IF EXISTS inventory_member_locations');
    }
}
