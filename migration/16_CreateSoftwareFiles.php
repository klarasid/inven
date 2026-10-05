<?php

use SLiMS\DB;

/**
 * Bukti lisensi on Perangkat Lunak: the files that show an application is legal (a licence
 * certificate, an invoice, a screenshot of its licence or activation page), several per
 * application. Safe to run again.
 */
class CreateSoftwareFiles extends \SLiMS\Migration\Migration
{
    public function up()
    {
        DB::getInstance()->exec("CREATE TABLE IF NOT EXISTS inventory_software_files (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            software_id INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            filename VARCHAR(80) NOT NULL,
            mime VARCHAR(40) NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY inventory_software_files_software (software_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        // The files stay in images/inventaris-barang/lisensi; only what the database knows of them goes.
        DB::getInstance()->exec('DROP TABLE IF EXISTS inventory_software_files');
    }
}
