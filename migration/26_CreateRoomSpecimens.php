<?php

use SLiMS\DB;

/**
 * Signature specimens for the Kartu Inventaris Ruangan: one picture per signer of a room ("knowing"
 * and "manager"), printed in the signature space when the KIR is printed with specimens. Safe to run again.
 */
class CreateRoomSpecimens extends \SLiMS\Migration\Migration
{
    public function up()
    {
        DB::getInstance()->exec("CREATE TABLE IF NOT EXISTS inventory_room_specimens (
            location_id INT UNSIGNED NOT NULL,
            role VARCHAR(10) NOT NULL,
            filename VARCHAR(48) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (location_id, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        // The pictures stay in images/inventaris-barang/spesimen; only what the database knows of them goes.
        DB::getInstance()->exec('DROP TABLE IF EXISTS inventory_room_specimens');
    }
}
