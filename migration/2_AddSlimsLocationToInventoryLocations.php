<?php

use SLiMS\DB;

class AddSlimsLocationToInventoryLocations extends \SLiMS\Migration\Migration
{
    public function up()
    {
        $db = DB::getInstance();
        // Safe to run again: SLiMS repeats migrations when its record of the applied version is lost.
        $exists = $db->query("SHOW COLUMNS FROM `inventory_locations` LIKE 'slims_location_id'")->fetchColumn();
        if ($exists) return;
        $db->exec(
            'ALTER TABLE `inventory_locations`
             ADD COLUMN `slims_location_id` VARCHAR(3) NULL AFTER `id`,
             ADD INDEX `inventory_locations_slims_location_index` (`slims_location_id`)'
        );
    }

    public function down()
    {
        $db = DB::getInstance();
        $db->exec(
            'ALTER TABLE `inventory_locations`
             DROP INDEX `inventory_locations_slims_location_index`,
             DROP COLUMN `slims_location_id`'
        );
    }
}
