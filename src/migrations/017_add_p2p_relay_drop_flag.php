<?php
/**
 * 017_add_p2p_relay_drop_flag.php
 *
 * Adds the is_drop flag to p2p_relay_channels. A "drop" is a channel whose host-to-guest queue is
 * retained (not deleted when read) until the share session expires, so a recipient who is not
 * online when the sender uploads can still download later. Everything is deleted by the
 * opportunistic cleanup once the session has been idle too long.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 */

$db = Flight::db();

Flight::logger()->info('Starting P2P relay drop flag Migration 017...');

$columns = array_column($db->query('PRAGMA table_info(p2p_relay_channels)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('is_drop', $columns, true)) {
    $db->exec('ALTER TABLE p2p_relay_channels ADD COLUMN is_drop INTEGER NOT NULL DEFAULT 0');
    Flight::logger()->info('Added is_drop column to p2p_relay_channels');
}

$db->exec("INSERT INTO migrations (name) VALUES ('017_add_p2p_relay_drop_flag')");
Flight::logger()->info('Migration 017 recorded.');
