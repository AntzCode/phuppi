<?php
/**
 * 012_add_p2p_shared_files_table.php
 *
 * Migration to create the p2p_shared_files table for P2P file sharing.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 */

$db = Flight::db();

Flight::logger()->info('Starting P2P Shared Files Migration 012...');

// Create p2p_shared_files table
$db->exec('
    CREATE TABLE IF NOT EXISTS p2p_shared_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        shortcode VARCHAR(12) NOT NULL UNIQUE,
        peerjs_id VARCHAR(64) NULL,
        pin VARCHAR(2) NOT NULL,
        pin_attempts TINYINT DEFAULT 0,
        pin_locked_at DATETIME NULL,
        files_metadata TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT 1,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
');
Flight::logger()->info('Created p2p_shared_files table');

// Create index for token
$db->exec('
    CREATE INDEX IF NOT EXISTS idx_p2p_token ON p2p_shared_files(token)
');
Flight::logger()->info('Created index idx_p2p_token');

// Create index for shortcode
$db->exec('
    CREATE INDEX IF NOT EXISTS idx_p2p_shortcode ON p2p_shared_files(shortcode)
');
Flight::logger()->info('Created index idx_p2p_shortcode');

// Create index for peerjs_id
$db->exec('
    CREATE INDEX IF NOT EXISTS idx_p2p_peerjs_id ON p2p_shared_files(peerjs_id)
');
Flight::logger()->info('Created index idx_p2p_peerjs_id');

// Record migration
if ($db->query("SELECT COUNT(*) FROM migrations WHERE name = '012_add_p2p_shared_files_table'")->fetchColumn() == 0) {
    $db->exec("INSERT INTO migrations (name) VALUES ('012_add_p2p_shared_files_table')");
    Flight::logger()->info('Migration 012 recorded.');
} else {
    Flight::logger()->info('Migration 012 already recorded.');
}

Flight::logger()->info('P2P Shared Files Migration 012 completed successfully.');
