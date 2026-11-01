<?php
/**
 * 015_add_p2p_receive_sessions_table.php
 *
 * Migration to create the p2p_receive_sessions table for reverse P2P file receiving.
 *
 * This enables logged-in users to create receive sessions where guests (no login)
 * can send files directly via WebRTC P2P.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 */

$db = Flight::db();

Flight::logger()->info('Starting P2P Receive Sessions Migration 015...');

// Create p2p_receive_sessions table
$db->exec('
    CREATE TABLE IF NOT EXISTS p2p_receive_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        shortcode VARCHAR(12) NOT NULL UNIQUE,
        peerjs_id VARCHAR(64) NULL,
        pin VARCHAR(2) NOT NULL,
        pin_attempts TINYINT DEFAULT 0,
        pin_locked_at DATETIME NULL,
        files_metadata TEXT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT 1,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
');

// Create indexes for performance
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_receive_sessions_user ON p2p_receive_sessions(user_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_receive_sessions_token ON p2p_receive_sessions(token)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_receive_sessions_shortcode ON p2p_receive_sessions(shortcode)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_receive_sessions_peerjs_id ON p2p_receive_sessions(peerjs_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_receive_sessions_expires ON p2p_receive_sessions(expires_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_receive_sessions_active ON p2p_receive_sessions(is_active)');

Flight::logger()->info('Created p2p_receive_sessions table with indexes');

// Record migration
$db->exec("INSERT INTO migrations (name) VALUES ('015_add_p2p_receive_sessions_table')");
Flight::logger()->info('Migration 015 recorded.');