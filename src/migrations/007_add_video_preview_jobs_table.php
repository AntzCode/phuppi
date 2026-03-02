<?php
/**
 * 007_add_video_preview_jobs_table.php
 *
 * Migration to create the video_preview_jobs table for queue management.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

$db = Flight::db();

Flight::logger()->info('Starting Video Preview Jobs Migration 007...');

// Create video_preview_jobs table if it doesn't exist
$db->exec('
    CREATE TABLE IF NOT EXISTS video_preview_jobs (
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
Flight::logger()->info('Created video_preview_jobs table');

// Create index for pending jobs
$db->exec('
    CREATE INDEX IF NOT EXISTS idx_video_preview_jobs_pending
    ON video_preview_jobs (status) WHERE status = "pending"
');
Flight::logger()->info('Created index for pending video preview jobs');

// Record migration
if ($db->query("SELECT COUNT(*) FROM migrations WHERE name = '007_add_video_preview_jobs_table'")->fetchColumn() == 0) {
    $db->exec("INSERT INTO migrations (name) VALUES ('007_add_video_preview_jobs_table')");
    Flight::logger()->info('Migration 007 recorded.');
} else {
    Flight::logger()->info('Migration 007 already recorded.');
}

Flight::logger()->info('Video Preview Jobs Migration 007 completed successfully.');