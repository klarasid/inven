<?php

use SLiMS\DB;

/**
 * Dokumen jaringan on Gedung & Jaringan: the files that show a location's internet (speed test
 * results, the ISP's service, the Wi-Fi coverage map), several per library location. The single
 * bandwidth evidence file stays where it is, in the facility figures. Safe to run again.
 */
class CreateNetworkDocuments extends \SLiMS\Migration\Migration
{
    public function up()
    {
        DB::getInstance()->exec("CREATE TABLE IF NOT EXISTS inventory_network_documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            library_code VARCHAR(20) NOT NULL DEFAULT '',
            kind VARCHAR(20) NOT NULL,
            location_id INT UNSIGNED NULL,
            title VARCHAR(150) NOT NULL,
            filename VARCHAR(80) NOT NULL,
            mime VARCHAR(40) NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY inventory_network_documents_library (library_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        // The files stay in images/inventaris-barang/jaringan; only what the database knows of them goes.
        DB::getInstance()->exec('DROP TABLE IF EXISTS inventory_network_documents');
    }
}
