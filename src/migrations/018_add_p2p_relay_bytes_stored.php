<?php
/**
 * 018_add_p2p_relay_bytes_stored.php
 *
 * Adds bytes_stored to p2p_relay_channels: the encrypted bytes currently held on disk for a channel
 * (written minus acknowledged). The relay now buffers up to a byte limit per session instead of a
 * fixed number of batches, so a fast sender is not held to the speed of a slow recipient.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 */

$db = Flight::db();

Flight::logger()->info('Starting P2P relay bytes_stored Migration 018...');

$columns = array_column($db->query('PRAGMA table_info(p2p_relay_channels)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('bytes_stored', $columns, true)) {
    $db->exec('ALTER TABLE p2p_relay_channels ADD COLUMN bytes_stored INTEGER NOT NULL DEFAULT 0');
    Flight::logger()->info('Added bytes_stored column to p2p_relay_channels');
}

$db->exec("INSERT INTO migrations (name) VALUES ('018_add_p2p_relay_bytes_stored')");
Flight::logger()->info('Migration 018 recorded.');
