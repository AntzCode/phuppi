<?php
/**
 * QueueController.php
 *
 * Controller for queue processing endpoints (AJAX mode).
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\Queue\QueueManager;
use Phuppi\Permissions\Middleware\IsAuthenticated;

class QueueController
{
    /**
     * Process batch of preview jobs (AJAX mode)
     */
    public function process(): void
    {
        $data = Flight::request()->data;
        $limit = (int)($data->limit ?? 5);
        $limit = max(1, min($limit, 10)); // Limit between 1-10

        $queue = new QueueManager();
        $result = $queue->processBatch($limit);

        Flight::json($result);
    }

    /**
     * Get queue status
     */
    public function status(): void
    {
        $queue = new QueueManager();
        $queue->cleanupExpiredLocks();
        $db = Flight::db();
        $pending = $db->query('SELECT COUNT(*) FROM preview_jobs WHERE status = "pending"')->fetchColumn();
        $processing = $db->query('SELECT COUNT(*) FROM preview_jobs WHERE status = "processing"')->fetchColumn();
        $failed = $db->query('SELECT COUNT(*) FROM preview_jobs WHERE status = "failed"')->fetchColumn();

        Flight::json([
            'pending' => (int)$pending,
            'processing' => (int)$processing,
            'failed' => (int)$failed,
            'mode' => $queue->getMode(),
            'max_concurrent' => $queue->getMaxConcurrent()
        ]);
    }

    /**
     * Get queue worker status for CLI mode
     */
    public function workerStatus(): void
    {
        $pidFile = Flight::get('flight.data.path') . '/queue-worker.pid';
        
        $status = 'stopped';
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if ($pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0)) {
                $status = 'running';
            } else {
                // Clean up stale PID file
                @unlink($pidFile);
            }
        }

        Flight::json(['status' => $status]);
    }
}
