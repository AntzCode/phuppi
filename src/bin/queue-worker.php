<#! /usr/bin/env php
<?php
/**
 * queue-worker.php
 *
 * CLI queue worker for processing preview generation jobs.
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
use Phuppi\Helper;

if (!Helper::isCli()) {
    die("This script must be run from CLI\n");
}

echo "Phuppi Preview Queue Worker v1.0.0\n";
echo "===================================\n\n";

$queue = new QueueManager();
$processed = 0;
$failed = 0;

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
    
    $job = $queue->claimNext();
    if ($job) {
        echo "[Job {$job->id}] Processing file {$job->uploaded_file_id}...\n";
        
        $success = $queue->processJob($job);
        if ($success) {
            $processed++;
            echo "[Job {$job->id}] Completed ✓\n";
        } else {
            $failed++;
            echo "[Job {$job->id}] Failed ✗\n";
        }
    } else {
        echo "No pending jobs. Sleeping 5s...\n";
    }
    
    sleep(5);
}

// Clean up PID file
if (file_exists($pidFile)) {
    unlink($pidFile);
}

echo "\nWorker stopped. Processed: $processed, Failed: $failed\n";
