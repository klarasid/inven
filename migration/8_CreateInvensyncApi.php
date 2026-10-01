<?php

use SLiMS\DB;

/**
 * Tables behind the Klaras InvenSync API: staff app sessions and the replay
 * store that makes offline retries safe. Nothing here touches inventory data.
 */
class CreateInvensyncApi extends \SLiMS\Migration\Migration
{
    public function up()
    {
        $db = DB::getInstance();
        // Tokens are stored as SHA-256 of their secret half; the selector finds the row.
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_api_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            access_selector CHAR(32) NOT NULL,
            access_hash CHAR(64) NOT NULL,
            access_expires_at DATETIME NOT NULL,
            refresh_selector CHAR(32) NULL,
            refresh_hash CHAR(64) NULL,
            refresh_expires_at DATETIME NULL,
            previous_refresh_selector CHAR(32) NULL,
            password_fingerprint CHAR(64) NOT NULL,
            device_name VARCHAR(100) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            UNIQUE KEY inventory_api_access (access_selector),
            UNIQUE KEY inventory_api_refresh (refresh_selector),
            KEY inventory_api_previous (previous_refresh_selector),
            KEY inventory_api_user (user_id, revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_api_idempotency (
            key_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            status SMALLINT NOT NULL DEFAULT 0,
            body MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            KEY inventory_api_idempotency_age (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        $db = DB::getInstance();
        $db->exec('DROP TABLE IF EXISTS inventory_api_idempotency');
        $db->exec('DROP TABLE IF EXISTS inventory_api_sessions');
    }
}
