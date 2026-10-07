<?php

use SLiMS\DB;

/**
 * Feedback threads go both ways: a librarian answers in their own thread, usually with the detail
 * Klaras asked for. Their answer is kept as a reply of kind "sender", without a panel id until it
 * has been sent. Each piece also keeps whether Klaras is waiting on the librarian, and whether the
 * thread still takes answers. Safe to run again: each change is applied only when it is missing.
 */
class AddFeedbackThreads extends \SLiMS\Migration\Migration
{
    /** $column is one of ours, never input. */
    private static function hasColumn(\PDO $db, string $column): bool
    {
        return (bool) $db->query("SHOW COLUMNS FROM inventory_feedback LIKE '" . $column . "'")->fetchColumn();
    }

    public function up()
    {
        $db = DB::getInstance();
        if (!self::hasColumn($db, 'awaiting_reply')) {
            $db->exec('ALTER TABLE inventory_feedback ADD COLUMN awaiting_reply TINYINT NOT NULL DEFAULT 0 AFTER issue_url');
        }
        if (!self::hasColumn($db, 'can_reply')) {
            $db->exec('ALTER TABLE inventory_feedback ADD COLUMN can_reply TINYINT NOT NULL DEFAULT 1 AFTER awaiting_reply');
        }
        // An answer waiting to be sent has no panel id yet.
        $db->exec('ALTER TABLE inventory_feedback_replies MODIFY panel_reply_id INT UNSIGNED NULL');
    }

    public function down()
    {
        $db = DB::getInstance();
        $db->exec("DELETE FROM inventory_feedback_replies WHERE panel_reply_id IS NULL");
        $db->exec('ALTER TABLE inventory_feedback_replies MODIFY panel_reply_id INT UNSIGNED NOT NULL');
        foreach (['can_reply', 'awaiting_reply'] as $column) {
            if (self::hasColumn($db, $column)) {
                $db->exec('ALTER TABLE inventory_feedback DROP COLUMN ' . $column);
            }
        }
    }
}
