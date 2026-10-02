<?php

use SLiMS\DB;

/**
 * A room's Area and Denah tabs: the areas inside each room and its floor plans.
 *
 * Rooms used to keep their functions as ticked codes. Each ticked function becomes an area of
 * that room; the old column stays as it was. Safe to run again: tables are created only when
 * missing, and a room that already has areas gets none added.
 */
class CreateRoomAreasAndPlans extends \SLiMS\Migration\Migration
{
    public function up()
    {
        require_once __DIR__ . '/../src/RoomAreas.php';
        $db = DB::getInstance();
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_room_areas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            location_id INT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL,
            name VARCHAR(150) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY inventory_room_areas_location (location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_room_plans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            location_id INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            filename VARCHAR(80) NOT NULL,
            mime VARCHAR(40) NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY inventory_room_plans_location (location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        \SLiMS\Plugins\Inventory\RoomAreas::importFunctions($db, date('Y-m-d H:i:s'));
    }

    public function down()
    {
        // Plan files stay in images/inventaris-barang/denah; only what the database knows of them goes.
        $db = DB::getInstance();
        $db->exec('DROP TABLE IF EXISTS inventory_room_plans');
        $db->exec('DROP TABLE IF EXISTS inventory_room_areas');
    }
}
