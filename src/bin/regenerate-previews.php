<#! /usr/bin/env php
<?php
/**
 * regenerate-previews.php
 *
 * CLI tool to regenerate all preview images and video previews.
 *
 * Usage: 
 *   php bin/regenerate-previews.php           # Regenerate image previews only
 *   php bin/regenerate-previews.php --video   # Regenerate video previews only
 *   php bin/regenerate-previews.php --all     # Regenerate both image and video previews
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

// Parse command line options
$options = getopt('', ['video', 'all', 'help']);
$regenerateVideo = isset($options['video']) || isset($options['all']);
$regenerateImages = !isset($options['video']);
$regenerateAll = isset($options['all']);

if (isset($options['help'])) {
    echo "Phuppi Preview Regenerator v2.0.0\n";
    echo "==================================\n\n";
    echo "Usage: php bin/regenerate-previews.php [options]\n\n";
    echo "Options:\n";
    echo "  --video   Regenerate video previews only\n";
    echo "  --all     Regenerate both image and video previews\n";
    echo "  --help    Show this help message\n\n";
    echo "Default: Regenerate image previews only\n";
    exit(0);
}

echo "Phuppi Preview Regenerator v2.0.0\n";
echo "==================================\n\n";

if ($regenerateAll) {
    echo "Mode: Regenerating BOTH image and video previews\n\n";
} elseif ($regenerateVideo) {
    echo "Mode: Regenerating video previews only\n\n";
} else {
    echo "Mode: Regenerating image previews only\n\n";
}

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

// ============================================
// Image Previews Regeneration
// ============================================
if ($regenerateImages) {
    echo "\n--- Image Previews ---\n\n";
    
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

    // 3. Delete old image preview jobs
    echo "Cleaning up old image preview jobs...\n";
    $db->exec('DELETE FROM preview_jobs');
    $db->exec('DELETE FROM queue_locks');
    echo "Old jobs cleaned.\n";

    // 4. Create new jobs for all files
    echo "Creating new image preview jobs...\n";
    $stmt = $db->query('SELECT id FROM uploaded_files');
    $created = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        QueueManager::createJob((int)$row['id']);
        $created++;
    }
    echo "Created $created new image preview jobs.\n";
}

// ============================================
// Video Previews Regeneration
// ============================================
if ($regenerateVideo) {
    echo "\n--- Video Previews ---\n\n";
    
    // 1. Delete all video preview files from storage
    echo "Deleting video preview files from storage...\n";
    $stmt = $db->prepare('SELECT DISTINCT uf.video_preview_filename, u.username AS getUsername FROM uploaded_files uf JOIN users u ON uf.user_id = u.id WHERE uf.video_preview_filename IS NOT NULL');
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $previewKey = $row['getUsername'] . '/video-previews/' . $row['video_preview_filename'];
        $storage->delete($previewKey);
    }
    echo "Video preview files deleted.\n";

    // 2. Reset video preview status in database
    echo "Resetting video preview status in database...\n";
    $db->exec('
        UPDATE uploaded_files 
        SET video_preview_filename = NULL, video_preview_status = "pending", video_preview_generated_at = NULL 
        WHERE video_preview_status != "pending"
    ');
    echo "Database reset.\n";

    // 3. Delete old video preview jobs
    echo "Cleaning up old video preview jobs...\n";
    $db->exec('DELETE FROM video_preview_jobs');
    echo "Old video preview jobs cleaned.\n";

    // 4. Create new video preview jobs for all video files
    echo "Creating new video preview jobs...\n";
    $stmt = $db->query('SELECT id FROM uploaded_files WHERE mimetype LIKE \'video/%\'');
    $created = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        QueueManager::createVideoPreviewJob((int)$row['id']);
        $created++;
    }
    echo "Created $created new video preview jobs.\n";
}

echo "\n==================================\n";
if ($regenerateAll) {
    echo "Regeneration complete for both image and video previews.\n";
} elseif ($regenerateVideo) {
    echo "Regeneration complete for video previews.\n";
} else {
    echo "Regeneration complete for image previews.\n";
}
echo "Run the queue worker to process jobs.\n";
