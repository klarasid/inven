<?php

use SLiMS\DB;

/**
 * An item may have several categories, such as a computer that is also multimedia equipment. They
 * are kept in the same column as comma-separated codes, like a room's functions, so the column
 * grows to hold every category at once. Existing values stay as they are.
 */
class AllowSeveralItemCategories extends \SLiMS\Migration\Migration
{
    /** The column's length, or null when it is not there. */
    private static function length(\PDO $db): ?int
    {
        $query = $db->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $query->execute(['inventory_items', 'category']);
        $length = $query->fetchColumn();
        return $length === false ? null : (int) $length;
    }

    public function up()
    {
        $db = DB::getInstance();
        $length = self::length($db);
        if ($length !== null && $length < 100) {
            $db->exec('ALTER TABLE inventory_items MODIFY category VARCHAR(100) NULL');
        }
    }

    public function down()
    {
        $db = DB::getInstance();
        $length = self::length($db);
        if ($length === null || $length <= 30) return;
        // Back to one category per item: each keeps its first.
        $db->exec("UPDATE inventory_items SET category = SUBSTRING_INDEX(category, ',', 1) WHERE category LIKE '%,%'");
        $db->exec('ALTER TABLE inventory_items MODIFY category VARCHAR(30) NULL');
    }
}
