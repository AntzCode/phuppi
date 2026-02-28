<?php
/**
 * QueueManager.php
 *
 * Manager for preview generation queue with CLI and AJAX support.
 *
 * @package Phuppi\Queue
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

namespace Phuppi\Queue;

use Flight;
use Phuppi\Service\PreviewGenerator;
use Exception;

class QueueManager
{
    private string $mode;
    private int $maxConcurrent;
    private int $lockTimeoutMinutes = 5;

    public function __construct()
    {
        $this->mode = $this->getSetting('queue_mode', 'cli');
        $this->maxConcurrent = (int) $this->getSetting('max_concurrent', 5);
    }

    /**
     * Create preview job for file
     */
    public static function createJob(int $fileId): PreviewJob
    {
        $job = PreviewJob::createForFile($fileId);
        Flight::logger()->info("Created preview job {$job->id} for file $fileId");
        return $job;
    }

    /**
     * Claim next job (CLI mode) with transaction handling and retry logic
     */
    public function claimNext(int $maxRetries = 3): ?PreviewJob
    {
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                $db = Flight::db();
                
                // Begin immediate transaction for SQLite locking
                $db->exec('BEGIN IMMEDIATE');
                
                // Cleanup expired locks first
                $this->cleanupExpiredLocks($db);
                
                // Try to claim job and acquire lock
                $stmt = $db->prepare('
                    SELECT pj.id FROM preview_jobs pj
                    WHERE pj.status = "pending"
                    AND NOT EXISTS (
                        SELECT 1 FROM queue_locks ql
                        WHERE ql.job_id = pj.id
                        AND ql.expires_at > datetime("now")
                    )
                    ORDER BY pj.created_at ASC
                    LIMIT 1
                ');
                $stmt->execute();
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if (!$row) {
                    $db->exec('ROLLBACK');
                    return null;
                }
                
                $jobId = (int)$row['id'];
                
                // Update job status
                $updateStmt = $db->prepare('
                    UPDATE preview_jobs
                    SET status = "processing", attempts = attempts + 1
                    WHERE id = ?
                ');
                $updateStmt->execute([$jobId]);
                
                // Acquire lock
                $lockToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->lockTimeoutMinutes} minutes"));
                $lockStmt = $db->prepare('
                    INSERT INTO queue_locks (job_id, lock_token, expires_at)
                    VALUES (?, ?, ?)
                ');
                $lockStmt->execute([$jobId, $lockToken, $expiresAt]);
                
                // Commit transaction
                $db->exec('COMMIT');
                
                // Fetch and return the claimed job
                $job = PreviewJob::findById($jobId);
                if ($job) {
                    Flight::logger()->info("Claimed job {$job->id} for file {$job->uploaded_file_id}");
                }
                return $job;
                
            } catch (\PDOException $e) {
                $attempt++;
                try {
                    Flight::db()->exec('ROLLBACK');
                } catch (\Exception $rollbackEx) {
                    // Ignore rollback errors
                }
                
                if ($attempt >= $maxRetries) {
                    Flight::logger()->error("Failed to claim job after {$attempt} attempts: " . $e->getMessage());
                    return null;
                }
                
                // Wait before retry (exponential backoff)
                usleep(50000 * $attempt); // 50ms, 100ms, 150ms...
            }
        }
        
        return null;
    }
    
    /**
     * Process batch of jobs (AJAX mode)
     */
    public function processBatch(int $limit = 0): array
    {
        $limit = $limit ?: $this->maxConcurrent;
        $results = [];
        $processed = 0;

        while ($processed < $limit) {
            $job = $this->claimNext();
            if (!$job) break;

            $success = $this->processJob($job);
            $results[] = [
                'job_id' => $job->id,
                'file_id' => $job->uploaded_file_id,
                'success' => $success
            ];

            $processed++;
        }

        $hasMore = $this->hasPendingJobs();
        return [
            'processed' => $processed,
            'has_more' => $hasMore,
            'results' => $results
        ];
    }

    /**
     * Process single job
     */
    public function processJob(PreviewJob $job): bool
    {
        try {
            $generator = new PreviewGenerator();
            $success = $generator->generate($job->uploaded_file_id, true); // Skip permission check for queue workers

            if ($success) {
                $job->markComplete();
            } else {
                $job->markFailed('Preview generation failed');
            }
            return $success;
        } catch (Exception $e) {
            Flight::logger()->error("Job {$job->id} failed: " . $e->getMessage());
            $job->markFailed($e->getMessage());
            return false;
        }
    }

    /**
     * Cleanup expired locks
     * @param \PDO|null $db Optional database connection for transaction context
     */
    public function cleanupExpiredLocks($db = null): void
    {
        $db = $db ?? Flight::db();
        // SQLite-compatible: update via join with subquery
        $db->exec('
            UPDATE preview_jobs
            SET status = "failed", last_error = "Lock expired - job timed out", processed_at = datetime("now")
            WHERE status = "processing"
            AND id IN (SELECT job_id FROM queue_locks WHERE expires_at < datetime("now"))
        ');
        $db->exec('DELETE FROM queue_locks WHERE expires_at < datetime("now")');
    }

    /**
     * Check if pending jobs exist
     */
    public function hasPendingJobs(): bool
    {
        $db = Flight::db();
        $count = $db->query('SELECT COUNT(*) FROM preview_jobs WHERE status = "pending"')->fetchColumn();
        return (int)$count > 0;
    }

    /**
     * Get queue mode
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Get max concurrent jobs setting
     */
    public function getMaxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    /**
     * Get setting value
     */
    private function getSetting(string $name, $default = null): string
    {
        $db = Flight::db();
        $fullName = "preview_$name";
        $stmt = $db->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$fullName]);
        $value = $stmt->fetchColumn();
        return $value ?: $default;
    }
}
