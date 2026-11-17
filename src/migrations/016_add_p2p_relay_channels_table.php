<?php
/**
 * 016_add_p2p_relay_channels_table.php
 *
 * Migration to create the p2p_relay_channels table. A channel is one recipient connection that
 * falls back from WebRTC to the PHP relay. The relay itself is a small bounded mailbox, so the
 * table only tracks sequence numbers and activity; the (encrypted) batches live on disk.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 */

$db = Flight::db();

Flight::logger()->info('Starting P2P Relay Channels Migration 016...');

// g2h = guest to host, h2g = host to guest. "last" is the newest stored sequence number
// and "acked" the newest one the reader has confirmed (and the server has deleted).
$db->exec('
    CREATE TABLE IF NOT EXISTS p2p_relay_channels (
        rid INTEGER PRIMARY KEY AUTOINCREMENT,
        id VARCHAR(32) NOT NULL UNIQUE,
        session_type VARCHAR(10) NOT NULL,
        session_token VARCHAR(64) NOT NULL,
        created_at INTEGER NOT NULL,
        last_activity INTEGER NOT NULL,
        closed INTEGER NOT NULL DEFAULT 0,
        g2h_last INTEGER NOT NULL DEFAULT 0,
        g2h_acked INTEGER NOT NULL DEFAULT 0,
        h2g_last INTEGER NOT NULL DEFAULT 0,
        h2g_acked INTEGER NOT NULL DEFAULT 0,
        bytes_total INTEGER NOT NULL DEFAULT 0
    )
');

$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_relay_session ON p2p_relay_channels(session_type, session_token)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_p2p_relay_activity ON p2p_relay_channels(last_activity)');

Flight::logger()->info('Created p2p_relay_channels table with indexes');

$db->exec("INSERT INTO migrations (name) VALUES ('016_add_p2p_relay_channels_table')");
Flight::logger()->info('Migration 016 recorded.');
