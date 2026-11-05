<?php

use SLiMS\DB;

/**
 * Electronic signatures of printed documents (KIR, inventory lists, Rekap Sarpras): who signed,
 * when, and a fingerprint (SHA-256) of the document's content, found again by the code in the QR
 * printed on it. The row keeps no content, only what the verification page shows. Safe to run again.
 */
class CreateSignedDocuments extends \SLiMS\Migration\Migration
{
    public function up()
    {
        DB::getInstance()->exec("CREATE TABLE IF NOT EXISTS inventory_signed_documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code CHAR(16) NOT NULL,
            doc_type VARCHAR(20) NOT NULL,
            title VARCHAR(200) NOT NULL,
            scope VARCHAR(200) NOT NULL DEFAULT '',
            signer_name VARCHAR(150) NOT NULL,
            signer_id INT UNSIGNED NULL,
            content_hash CHAR(64) NOT NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            signed_at DATETIME NOT NULL,
            UNIQUE KEY inventory_signed_documents_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        DB::getInstance()->exec('DROP TABLE IF EXISTS inventory_signed_documents');
    }
}
