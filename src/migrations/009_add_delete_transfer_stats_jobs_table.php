<?php
/**
 * 009_add_delete_transfer_stats_jobs_table.php
 *
 * Migration to create the delete_transfer_stats_jobs table for queue management.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 */

$db = Flight::db();

Flight::logger()->info('Starting Delete Transfer Stats Jobs Migration 009...');

// Create delete_transfer_stats_jobs table if it doesn't exist
$db->exec('
    CREATE TABLE IF NOT EXISTS delete_transfer_stats_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uploaded_file_id INTEGER NOT NULL,
        status VARCHAR(20) DEFAULT "pending",
        attempts INTEGER DEFAULT 0,
        last_error TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        FOREIGN KEY (uploaded_file_id) REFERENCES uploaded_files(id) ON DELETE CASCADE
    )
');
Flight::logger()->info('Created delete_transfer_stats_jobs table');

// Create index for pending jobs
$db->exec('
    CREATE INDEX IF NOT EXISTS idx_delete_transfer_stats_jobs_pending
    ON delete_transfer_stats_jobs (status) WHERE status = "pending"
');
Flight::logger()->info('Created index for pending delete transfer stats jobs');

// Record migration
if ($db->query("SELECT COUNT(*) FROM migrations WHERE name = '009_add_delete_transfer_stats_jobs_table'")->fetchColumn() == 0) {
    $db->exec("INSERT INTO migrations (name) VALUES ('009_add_delete_transfer_stats_jobs_table')");
    Flight::logger()->info('Migration 009 recorded.');
} else {
    Flight::logger()->info('Migration 009 already recorded.');
}

Flight::logger()->info('Delete Transfer Stats Jobs Migration 009 completed successfully.');
