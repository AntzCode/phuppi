<?php
/**
 * 004_add_preview_fields.php
 *
 * Migration to add preview image support to uploaded_files table and create preview queue tables.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

$db = Flight::db();

Flight::logger()->info('Starting Preview Migration 004...');

// Add columns to uploaded_files if they don't exist
$columns = $db->query("PRAGMA table_info(uploaded_files)")->fetchAll(PDO::FETCH_COLUMN);
$existingColumns = array_flip($columns);

$addColumns = [
    'preview_filename VARCHAR(255) NULL',
    'preview_status VARCHAR(20) DEFAULT \'pending\'',
    'preview_generated_at DATETIME NULL'
];

foreach ($addColumns as $column) {
    $colName = trim(explode(' ', $column)[0]);
    if (!isset($existingColumns[$colName])) {
        $db->exec("ALTER TABLE uploaded_files ADD COLUMN $column");
        Flight::logger()->info("Added column $colName to uploaded_files");
    } else {
        Flight::logger()->info("Column $colName already exists");
    }
}

// Create preview_jobs table
$db->exec("CREATE TABLE IF NOT EXISTS preview_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uploaded_file_id INTEGER NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    attempts INTEGER DEFAULT 0,
    last_error TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    FOREIGN KEY (uploaded_file_id) REFERENCES uploaded_files (id) ON DELETE CASCADE
)");

// Create queue_locks table
$db->exec("CREATE TABLE IF NOT EXISTS queue_locks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    lock_token VARCHAR(64) NOT NULL,
    locked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (job_id) REFERENCES preview_jobs (id) ON DELETE CASCADE,
    UNIQUE(job_id)
)");

// Insert default preview settings (INSERT OR IGNORE)
$defaultSettings = [
    'preview_queue_mode' => 'cli',
    'preview_max_concurrent' => '5',
    'preview_debounce_ms' => '300',
    'preview_width' => '300',
    'preview_height' => '300',
    'preview_format' => 'jpeg',
    'preview_quality' => '80',
    'preview_max_size_kb' => '50'
];

$stmt = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)');
foreach ($defaultSettings as $name => $value) {
    $stmt->execute([$name, $value]);
}
$stmt = null;

Flight::logger()->info('Preview Migration 004 completed successfully.');

// Record migration
if ($db->query("SELECT COUNT(*) FROM migrations WHERE name = '004_add_preview_fields'")->fetchColumn() == 0) {
    $db->exec("INSERT INTO migrations (name) VALUES ('004_add_preview_fields')");
}
Flight::logger()->info('Migration 004 recorded.');
