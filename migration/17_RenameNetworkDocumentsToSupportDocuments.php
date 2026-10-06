<?php

use SLiMS\DB;

/**
 * Network documents become one kind of supporting document (SupportDocuments), beside the
 * security and safety documents the same table now holds: its table and its folder take the
 * wider name. A SLiMS that never ran migration 15 gets the table here. Safe to run again.
 */
class RenameNetworkDocumentsToSupportDocuments extends \SLiMS\Migration\Migration
{
    private static function has(\PDO $db, string $table): bool
    {
        $query = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $query->execute([$table]);
        return (bool) $query->fetchColumn();
    }

    public function up()
    {
        $db = DB::getInstance();
        if (!self::has($db, 'inventory_support_documents')) {
            if (self::has($db, 'inventory_network_documents')) {
                $db->exec('RENAME TABLE inventory_network_documents TO inventory_support_documents');
            } else {
                $db->exec("CREATE TABLE inventory_support_documents (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    library_code VARCHAR(20) NOT NULL DEFAULT '',
                    kind VARCHAR(20) NOT NULL,
                    location_id INT UNSIGNED NULL,
                    title VARCHAR(150) NOT NULL,
                    filename VARCHAR(80) NOT NULL,
                    mime VARCHAR(40) NOT NULL,
                    created_by INT NULL,
                    created_at DATETIME NOT NULL,
                    KEY inventory_support_documents_library (library_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        }
        // The files follow where nothing is in the way; one left behind is still found (SupportDocuments).
        require_once __DIR__ . '/../src/SupportDocuments.php';
        $former = \SLiMS\Plugins\Inventory\SupportDocuments::formerDirectory();
        $folder = \SLiMS\Plugins\Inventory\SupportDocuments::directory();
        if (is_dir($former) && !is_link($former) && !file_exists($folder)) @rename($former, $folder);
    }

    public function down()
    {
        // The files stay in images/inventaris-barang/dokumen, where SupportDocuments finds them again.
        $db = DB::getInstance();
        if (self::has($db, 'inventory_support_documents') && !self::has($db, 'inventory_network_documents')) {
            $db->exec('RENAME TABLE inventory_support_documents TO inventory_network_documents');
        }
    }
}
