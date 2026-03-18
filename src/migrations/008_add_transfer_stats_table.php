<?php
/**
 * 008_add_transfer_stats_table.php
 *
 * Migration to create the transfer_stats table for tracking storage transfer statistics.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

$db = Flight::db();

Flight::logger()->info('Starting Transfer Stats Migration 008...');

// Get data path for the transfers database
$dataPath = Flight::get('flight.data.path');
$transfersDbPath = $dataPath . DIRECTORY_SEPARATOR . 'transfers.sqlite';

// Create the transfers database connection
$transfersDb = new PDO('sqlite:' . $transfersDbPath);
$transfersDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Configure WAL mode for high-write performance
$transfersDb->exec('PRAGMA journal_mode = WAL');
$transfersDb->exec('PRAGMA busy_timeout = 5000');
$transfersDb->exec('PRAGMA synchronous = FULL');
$transfersDb->exec('PRAGMA cache_size = -10000');
$transfersDb->exec('PRAGMA wal_autocheckpoint = 1000');

Flight::logger()->info('Created transfers database at: ' . $transfersDbPath);

// Create transfer_stats table
$transfersDb->exec('
    CREATE TABLE IF NOT EXISTS transfer_stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        connector_name VARCHAR(255) NOT NULL,
        file_id INTEGER,
        user_id INTEGER,
        voucher_id INTEGER,
        direction VARCHAR(10) NOT NULL,
        operation_type VARCHAR(50) NOT NULL,
        bytes_transferred INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');
Flight::logger()->info('Created transfer_stats table');

// Create indexes
$transfersDb->exec('
    CREATE INDEX IF NOT EXISTS idx_transfer_stats_connector
    ON transfer_stats (connector_name, created_at)
');
Flight::logger()->info('Created index idx_transfer_stats_connector');

$transfersDb->exec('
    CREATE INDEX IF NOT EXISTS idx_transfer_stats_file
    ON transfer_stats (file_id, created_at)
');
Flight::logger()->info('Created index idx_transfer_stats_file');

// Record migration in the main database
if ($db->query("SELECT COUNT(*) FROM migrations WHERE name = '008_add_transfer_stats_table'")->fetchColumn() == 0) {
    $db->exec("INSERT INTO migrations (name) VALUES ('008_add_transfer_stats_table')");
    Flight::logger()->info('Migration 008 recorded.');
} else {
    Flight::logger()->info('Migration 008 already recorded.');
}

Flight::logger()->info('Transfer Stats Migration 008 completed successfully.');