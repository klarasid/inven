<?php

use SLiMS\DB;

/**
 * Data behind the Rekap Sarpras page: floor area and
 * service functions of rooms, a category and type for items, and a register of the software the
 * library runs. Existing rows stay as they are; the new columns start empty.
 */
class AddFacilityProfileData extends \SLiMS\Migration\Migration
{
    private static function hasColumn(\PDO $db, string $table, string $column): bool
    {
        $query = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $query->execute([$table, $column]);
        return (bool) $query->fetchColumn();
    }

    public function up()
    {
        $db = DB::getInstance();
        if (!self::hasColumn($db, 'inventory_locations', 'area_m2')) {
            $db->exec('ALTER TABLE inventory_locations
                ADD COLUMN area_m2 DECIMAL(10,2) NULL AFTER room_name,
                ADD COLUMN room_functions VARCHAR(255) NOT NULL DEFAULT \'\' AFTER area_m2');
        }
        if (!self::hasColumn($db, 'inventory_items', 'category')) {
            $db->exec('ALTER TABLE inventory_items
                ADD COLUMN category VARCHAR(30) NULL AFTER item_name,
                ADD COLUMN item_type VARCHAR(100) NOT NULL DEFAULT \'\' AFTER category,
                ADD INDEX inventory_items_category_index (category)');
        }
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_software (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            version VARCHAR(50) NOT NULL DEFAULT '',
            purpose VARCHAR(255) NOT NULL DEFAULT '',
            licence VARCHAR(20) NOT NULL,
            licence_ref VARCHAR(255) NOT NULL DEFAULT '',
            valid_until DATE NULL,
            installs INT UNSIGNED NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY inventory_software_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        $db = DB::getInstance();
        $db->exec('DROP TABLE IF EXISTS inventory_software');
        if (self::hasColumn($db, 'inventory_items', 'category')) {
            $db->exec('ALTER TABLE inventory_items DROP INDEX inventory_items_category_index, DROP COLUMN item_type, DROP COLUMN category');
        }
        if (self::hasColumn($db, 'inventory_locations', 'area_m2')) {
            $db->exec('ALTER TABLE inventory_locations DROP COLUMN room_functions, DROP COLUMN area_m2');
        }
    }
}
