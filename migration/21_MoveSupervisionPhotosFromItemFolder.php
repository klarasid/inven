<?php

/**
 * Inspection and finding photos taken in Klaras InvenSync were stored with the item photos, while
 * the supervision pages look for them in pengawasan/. Moves each one there. Safe to run again.
 */
class MoveSupervisionPhotosFromItemFolder extends \SLiMS\Migration\Migration
{
    public function up()
    {
        require_once dirname(__DIR__) . '/src/PhotoStorage.php';
        $db = \SLiMS\DB::getInstance();
        if (!$db->query("SHOW TABLES LIKE 'inventory_watch_photos'")->fetchColumn()) {
            return;
        }
        $from = SB . 'images/inventaris-barang';
        $to = $from . '/pengawasan';
        foreach ($db->query('SELECT filename FROM inventory_watch_photos')->fetchAll(PDO::FETCH_COLUMN) as $filename) {
            if (!preg_match('/\A[a-f0-9]{64}\.jpg\z/', (string) $filename)) {
                continue;
            }
            $stray = $from . '/' . $filename;
            if (!is_file($stray) || is_link($stray) || file_exists($to . '/' . $filename)) {
                continue;
            }
            \SLiMS\Plugins\Inventory\PhotoStorage::protect($to);
            if (!rename($stray, $to . '/' . $filename)) {
                error_log('Inventory supervision photo ' . $filename . ' could not be moved to pengawasan/.');
            }
        }
    }

    public function down()
    {
        // The photos stay where the supervision pages find them.
    }
}
