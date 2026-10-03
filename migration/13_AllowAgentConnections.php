<?php

use SLiMS\DB;

/**
 * Agent AI connections (Api\AgentCodes): the one-time codes a librarian's consent hands to Klaras
 * Panel, and a kind on each InvenSync session so agent sessions can be told apart from phones.
 * Safe to run again: each change is applied only when it is still missing.
 */
class AllowAgentConnections extends \SLiMS\Migration\Migration
{
    private static function hasColumn(\PDO $db, string $table, string $column): bool
    {
        $query = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $query->execute([$table, $column]);
        return (bool) $query->fetchColumn();
    }

    public function up()
    {
        $db = DB::getInstance();
        // Only SHA-256 of the code is stored; the code itself is seen once, in the redirect.
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_api_codes (
            code_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            client_name VARCHAR(60) NOT NULL,
            code_challenge VARCHAR(128) NOT NULL,
            redirect_uri VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            KEY inventory_api_codes_age (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!self::hasColumn($db, 'inventory_api_sessions', 'kind')) {
            $db->exec("ALTER TABLE inventory_api_sessions ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'app' AFTER user_id");
        }
    }

    public function down()
    {
        $db = DB::getInstance();
        $db->exec('DROP TABLE IF EXISTS inventory_api_codes');
        if (self::hasColumn($db, 'inventory_api_sessions', 'kind')) {
            $db->exec('ALTER TABLE inventory_api_sessions DROP COLUMN kind');
        }
    }
}
