<?php
/**
 * 006_add_video_preview_fields.php
 *
 * Migration to add video preview fields to uploaded_files table.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

$db = Flight::db();

Flight::logger()->info('Starting Video Preview Migration 006...');

// Add columns to uploaded_files if they don't exist
$columns = $db->query("PRAGMA table_info(uploaded_files)")->fetchAll(PDO::FETCH_COLUMN);
$existingColumns = array_flip($columns);

$addColumns = [
    'video_preview_filename VARCHAR(255) NULL',
    'video_preview_status VARCHAR(20) DEFAULT \'pending\'',
    'video_preview_generated_at DATETIME NULL',
    'video_preview_poster_filename VARCHAR(255) NULL'
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

// Insert default video preview settings (INSERT OR IGNORE)
$defaultSettings = [
    'video_preview_enabled' => '1',
    'video_preview_resolution' => '720p',
    'video_preview_quality' => 'medium',
    'video_preview_audio' => '1',
    'video_preview_generate_poster' => '1',
    'video_preview_queue_mode' => 'cli'
];

$stmt = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)');
foreach ($defaultSettings as $name => $value) {
    $stmt->execute([$name, $value]);
}
$stmt = null;

Flight::logger()->info('Video Preview Migration 006 completed successfully.');

// Record migration
if ($db->query("SELECT COUNT(*) FROM migrations WHERE name = '006_add_video_preview_fields'")->fetchColumn() == 0) {
    $db->exec("INSERT INTO migrations (name) VALUES ('006_add_video_preview_fields')");
}
Flight::logger()->info('Migration 006 recorded.');
