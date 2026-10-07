<?php

use SLiMS\DB;

/**
 * Screenshots sent with feedback. Each is kept in images/inventaris-barang/masukan/ until Klaras has
 * it, then the copy here is deleted (it may show member data): the row stays, for the history.
 * Safe to run again.
 */
class CreateFeedbackAttachments extends \SLiMS\Migration\Migration
{
    public function up()
    {
        $db = DB::getInstance();
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_feedback_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feedback_id INT UNSIGNED NOT NULL,
            filename VARCHAR(40) NOT NULL,
            name VARCHAR(200) NOT NULL,
            mime VARCHAR(30) NOT NULL,
            size INT UNSIGNED NOT NULL,
            state VARCHAR(10) NOT NULL DEFAULT 'pending',
            panel_attachment_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY inventory_feedback_attachment (feedback_id),
            KEY inventory_feedback_attachment_state (state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        DB::getInstance()->exec('DROP TABLE IF EXISTS inventory_feedback_attachments');
    }
}
