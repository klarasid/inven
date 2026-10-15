<?php

use SLiMS\DB;

/**
 * Areas outside any room: a car park, a canteen or a garden belongs to the library, not to a
 * room. Such an area has no room (location_id NULL) and names its library location instead
 * (slims_location_id, NULL where SLiMS keeps no locations). Areas inside rooms stay as they are.
 * Safe to run again.
 */
class AddOutsideAreas extends \SLiMS\Migration\Migration
{
    private static function hasLibrary(\PDO $db): bool
    {
        return (bool) $db->query("SHOW COLUMNS FROM inventory_room_areas LIKE 'slims_location_id'")->fetchColumn();
    }

    public function up()
    {
        $db = DB::getInstance();
        if (!self::hasLibrary($db)) {
            $db->exec('ALTER TABLE inventory_room_areas
                MODIFY location_id INT UNSIGNED NULL,
                ADD COLUMN slims_location_id VARCHAR(3) NULL AFTER location_id,
                ADD INDEX inventory_room_areas_library (slims_location_id)');
        }
    }

    public function down()
    {
        $db = DB::getInstance();
        if (!self::hasLibrary($db)) return;
        // The files stay in images/inventaris-barang/area; only what the database knows of them goes.
        if ($db->query("SHOW TABLES LIKE 'inventory_area_photos'")->fetchColumn()) {
            $db->exec('DELETE p FROM inventory_area_photos p JOIN inventory_room_areas a ON a.id = p.area_id WHERE a.location_id IS NULL');
        }
        $db->exec('DELETE FROM inventory_room_areas WHERE location_id IS NULL');
        $db->exec('ALTER TABLE inventory_room_areas
            DROP INDEX inventory_room_areas_library,
            DROP COLUMN slims_location_id,
            MODIFY location_id INT UNSIGNED NOT NULL');
    }
}
