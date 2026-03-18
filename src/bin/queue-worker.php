<#! /usr/bin/env php
<?php
/**
 * queue-worker.php
 *
 * CLI queue worker for processing preview generation jobs (images and videos)
 * and delete transfer stats jobs.
 *
 * Usage: php bin/queue-worker.php
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
use Phuppi\Queue\DeleteTransferStatsJob;
use Phuppi\Service\TransferStats;
use Phuppi\Helper;

if (!Helper::isCli()) {
    die("This script must be run from CLI\n");
}

echo "Phuppi Preview Queue Worker v2.0.0\n";
echo "===================================\n\n";

$queue = new QueueManager();
$imageProcessed = 0;
$imageFailed = 0;
$videoProcessed = 0;
$videoFailed = 0;
$statsProcessed = 0;
$statsFailed = 0;

declare(ticks = 1);
pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
pcntl_signal(SIGINT, function() use (&$running) { $running = false; });

$running = true;

// Write PID file to indicate worker is running
$pidFile = Flight::get('flight.data.path') . '/queue-worker.pid';
file_put_contents($pidFile, getmypid());

echo "Starting queue worker (Press Ctrl+C to stop)...\n\n";

while ($running) {
    $queue->cleanupExpiredLocks();
    
    // Process image preview jobs
    $job = $queue->claimNext();
    if ($job) {
        echo "[Image Job {$job->id}] Processing file {$job->uploaded_file_id}...\n";
        
        $success = $queue->processJob($job);
        if ($success) {
            $imageProcessed++;
            echo "[Image Job {$job->id}] Completed ✓\n";
        } else {
            $imageFailed++;
            echo "[Image Job {$job->id}] Failed ✗\n";
        }
        continue; // Continue to next iteration after processing
    }
    
    // Process video preview jobs
    $videoJob = $queue->claimNextVideoPreview();
    if ($videoJob) {
        echo "[Video Job {$videoJob->id}] Processing file {$videoJob->uploaded_file_id}...\n";
        
        $success = $queue->processVideoPreviewJob($videoJob);
        if ($success) {
            $videoProcessed++;
            echo "[Video Job {$videoJob->id}] Completed ✓\n";
        } else {
            $videoFailed++;
            echo "[Video Job {$videoJob->id}] Failed ✗\n";
        }
        continue;
    }
    
    // Process delete transfer stats jobs
    $statsJob = $queue->claimNextDeleteTransferStatsJob();
    if ($statsJob) {
        echo "[Stats Job {$statsJob->id}] Deleting stats for file {$statsJob->uploaded_file_id}...\n";
        
        try {
            $transferStats = new TransferStats();
            $deleted = $transferStats->deleteByFileId($statsJob->uploaded_file_id);
            
            if ($deleted >= 0) {
                $statsJob->markComplete();
                $statsProcessed++;
                echo "[Stats Job {$statsJob->id}] Completed ✓ (deleted $deleted records)\n";
            } else {
                $statsJob->markFailed('Failed to delete transfer stats');
                $statsFailed++;
                echo "[Stats Job {$statsJob->id}] Failed ✗\n";
            }
        } catch (Exception $e) {
            $statsJob->markFailed($e->getMessage());
            $statsFailed++;
            echo "[Stats Job {$statsJob->id}] Failed ✗ ({$e->getMessage()})\n";
        }
        continue;
    }
    
    echo "No pending jobs. Sleeping 5s...\n";
    sleep(5);
}

// Clean up PID file
if (file_exists($pidFile)) {
    unlink($pidFile);
}

echo "\nWorker stopped.\n";
echo "Image previews - Processed: $imageProcessed, Failed: $imageFailed\n";
echo "Video previews - Processed: $videoProcessed, Failed: $videoFailed\n";
echo "Transfer stats - Processed: $statsProcessed, Failed: $statsFailed\n";
