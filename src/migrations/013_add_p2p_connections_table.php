<?php
/**
 * 013_add_p2p_connections_table.php
 *
 * Migration to create the p2p_connections table for tracking
 * individual recipient connections to P2P share sessions.
 *
 * This enables:
 * - Multiple recipients per session
 * - Connection status tracking
 * - Session persistence across page refreshes
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 */

$db = Flight::db();

Flight::logger()->info('Starting P2P Connections Migration 013...');

// Create p2p_connections table
$db->exec('
    CREATE TABLE IF NOT EXISTS p2p_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        p2p_token_id INTEGER NOT NULL,
        recipient_peerjs_id VARCHAR(64) NULL,
        recipient_ip VARCHAR(45) NULL,
        status VARCHAR(20) DEFAULT "connected",
        connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        disconnected_at DATETIME NULL,
        FOREIGN KEY (p2p_token_id) REFERENCES p2p_shared_files(id) ON DELETE CASCADE
    )
');

// Create indexes for performance
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_connections_token ON p2p_connections(p2p_token_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_connections_peerjs_id ON p2p_connections(recipient_peerjs_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_connections_status ON p2p_connections(status)');

Flight::logger()->info('Created p2p_connections table with indexes');

// Record migration
$db->exec("INSERT INTO migrations (name) VALUES ('013_add_p2p_connections_table')");
Flight::logger()->info('Migration 013 recorded.');
