<?php

use SLiMS\DB;

/**
 * Photos of the areas inside a room (the Area tab): a toilet, a car park or a reading area is an
 * area, not an item, and had nowhere to keep a picture. Several per area. Safe to run again.
 */
class CreateAreaPhotos extends \SLiMS\Migration\Migration
{
    public function up()
    {
        DB::getInstance()->exec("CREATE TABLE IF NOT EXISTS inventory_area_photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area_id INT UNSIGNED NOT NULL,
            filename VARCHAR(68) NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY inventory_area_photos_area (area_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        // The files stay in images/inventaris-barang/area; only what the database knows of them goes.
        DB::getInstance()->exec('DROP TABLE IF EXISTS inventory_area_photos');
    }
}
