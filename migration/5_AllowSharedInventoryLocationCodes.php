<?php

use SLiMS\DB;

class AllowSharedInventoryLocationCodes extends \SLiMS\Migration\Migration
{
    public function up()
    {
        // Each room has its own primary key; a card location code may be shared.
        // Safe to run again: each change is applied only when it is still missing.
        $db = DB::getInstance();
        $has = static function (string $index) use ($db): bool {
            return (bool) $db->query("SHOW INDEX FROM `inventory_locations` WHERE Key_name = " . $db->quote($index))->fetchColumn();
        };
        $changes = [];
        if ($has('inventory_locations_code_unique')) $changes[] = 'DROP INDEX `inventory_locations_code_unique`';
        if (!$has('inventory_locations_code_index')) $changes[] = 'ADD INDEX `inventory_locations_code_index` (`location_code`)';
        if ($changes) $db->exec('ALTER TABLE `inventory_locations` ' . implode(', ', $changes));
    }

    public function down()
    {
        // If shared codes exist, MySQL rejects this atomically without removing data.
        DB::getInstance()->exec(
            'ALTER TABLE `inventory_locations`
             DROP INDEX `inventory_locations_code_index`,
             ADD UNIQUE INDEX `inventory_locations_code_unique` (`location_code`)'
        );
    }
}
