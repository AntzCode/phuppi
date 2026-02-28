<#! /usr/bin/env php
<?php
/**
 * regenerate-previews.php
 *
 * CLI tool to regenerate all preview images.
 *
 * Usage: php bin/regenerate-previews.php
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

require_once __DIR__ . '/../bootstrap.php';

use Phuppi\Queue\QueueManager;
use Phuppi\Helper;

if (!Helper::isCli()) {
    die("This script must be run from CLI\n");
}

echo "Phuppi Preview Regenerator v1.0.0\n";
echo "==================================\n\n";

echo "WARNING: This will delete all existing previews and requeue all files for preview generation.\n";
echo "This operation cannot be undone. Continue? (y/N): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (trim($line) !== 'y') {
    echo "Aborted.\n";
    exit(1);
}

$db = Flight::db();
$storage = Flight::storage();

// 1. Delete all preview files from storage
echo "Deleting preview files from storage...\n";
$stmt = $db->prepare('SELECT DISTINCT uf.preview_filename, u.username AS getUsername FROM uploaded_files uf JOIN users u ON uf.user_id = u.id WHERE uf.preview_filename IS NOT NULL');
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $previewKey = $row['getUsername'] . '/previews/' . $row['preview_filename'];
    $storage->delete($previewKey);
}
echo "Preview files deleted.\n";

// 2. Reset preview status in database
echo "Resetting preview status in database...\n";
$db->exec('
    UPDATE uploaded_files 
    SET preview_filename = NULL, preview_status = "pending", preview_generated_at = NULL 
    WHERE preview_status != "pending"
');
echo "Database reset.\n";

// 3. Delete old jobs
echo "Cleaning up old jobs...\n";
$db->exec('DELETE FROM preview_jobs');
$db->exec('DELETE FROM queue_locks');
echo "Old jobs cleaned.\n";

// 4. Create new jobs for all files
echo "Creating new preview jobs...\n";
$stmt = $db->query('SELECT id FROM uploaded_files');
$created = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    QueueManager::createJob((int)$row['id']);
    $created++;
}
echo "Created $created new preview jobs.\n";

echo "\nRegeneration complete. Run the queue worker to process jobs.\n";
