<?php
/**
 * 019_add_p2p_relay_batches_table.php
 *
 * Creates p2p_relay_batches: one row per stored (encrypted) relay batch with its size. It lets the
 * relay accept batches out of order within a small lookahead (so a browser can upload several at once),
 * free the right number of bytes when a reader acknowledges, and clean up batch files on a storage
 * connector (such as S3) without listing the bucket. backend records where the batch was written ("local" or the storage
 * connector's name), so it is read and deleted from there even if the active connector changes.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 */

$db = Flight::db();

Flight::logger()->info('Starting P2P relay batches Migration 019...');

$db->exec('
    CREATE TABLE IF NOT EXISTS p2p_relay_batches (
        channel_id VARCHAR(32) NOT NULL,
        dir VARCHAR(3) NOT NULL,
        seq INTEGER NOT NULL,
        size INTEGER NOT NULL,
        backend VARCHAR(64) NOT NULL DEFAULT "local",
        PRIMARY KEY (channel_id, dir, seq)
    )
');

$db->exec("INSERT INTO migrations (name) VALUES ('019_add_p2p_relay_batches_table')");
Flight::logger()->info('Migration 019 recorded.');
