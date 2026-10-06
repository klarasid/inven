<?php

use SLiMS\DB;

/**
 * Feedback librarians send to Klaras from the Masukan button, kept here first so it is sent again
 * when Klaras cannot be reached, and the replies Klaras sends back. Safe to run again.
 */
class CreateFeedback extends \SLiMS\Migration\Migration
{
    public function up()
    {
        $db = DB::getInstance();
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_feedback (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            panel_id CHAR(36) NULL,
            token CHAR(48) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            page VARCHAR(100) NOT NULL DEFAULT '',
            user_id INT NULL,
            contact TINYINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            issue_url VARCHAR(255) NULL,
            attempts INT NOT NULL DEFAULT 0,
            sent_at DATETIME NULL,
            seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY inventory_feedback_panel (panel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_feedback_replies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feedback_id INT UNSIGNED NOT NULL,
            panel_reply_id INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            replied_at DATETIME NOT NULL,
            received_at DATETIME NOT NULL,
            UNIQUE KEY inventory_feedback_reply (feedback_id, panel_reply_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        $db = DB::getInstance();
        $db->exec('DROP TABLE IF EXISTS inventory_feedback_replies');
        $db->exec('DROP TABLE IF EXISTS inventory_feedback');
    }
}
