<?php
/**
 * 011_add_storage_connector_column.php
 *
 * Migration to add storage_connector column to uploaded_files table.
 * This allows tracking which storage connector was used for each file upload.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

$db = Flight::db();

Flight::logger()->info('Starting Storage Connector Migration 011...');

// Get existing columns
$columns = $db->query("PRAGMA table_info(uploaded_files)")->fetchAll(PDO::FETCH_COLUMN);
$existingColumns = array_flip($columns);

// Check if storage_connector column already exists
if (!isset($existingColumns['storage_connector'])) {
    // Add the column as nullable first
    $db->exec("ALTER TABLE uploaded_files ADD COLUMN storage_connector VARCHAR(255) NULL");
    Flight::logger()->info('Added column storage_connector to uploaded_files');
} else {
    Flight::logger()->info('Column storage_connector already exists');
}

// Get the current active_storage_connector from settings for default value
$stmt = $db->query("SELECT value FROM settings WHERE name = 'active_storage_connector'");
$activeConnector = $stmt->fetchColumn();
if ($activeConnector === false || empty($activeConnector)) {
    $activeConnector = 'local-default';
}
Flight::logger()->info('Active storage connector: ' . $activeConnector);

// Update existing records that have NULL storage_connector
$stmt = $db->prepare("UPDATE uploaded_files SET storage_connector = ? WHERE storage_connector IS NULL");
$stmt->execute([$activeConnector]);
$updatedCount = $stmt->rowCount();
Flight::logger()->info("Updated $updatedCount existing records with storage_connector = '$activeConnector'");

Flight::logger()->info('Storage Connector Migration 011 completed successfully.');

// Record migration
if ($db->query("SELECT COUNT(*) FROM migrations WHERE name = '011_add_storage_connector_column'")->fetchColumn() == 0) {
    $db->exec("INSERT INTO migrations (name) VALUES ('011_add_storage_connector_column')");
    Flight::logger()->info('Migration 011 recorded.');
} else {
    Flight::logger()->info('Migration 011 already recorded.');
}
