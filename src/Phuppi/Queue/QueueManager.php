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
use Phuppi\Service\VideoPreviewGenerator;
use Phuppi\UploadedFile;
use Exception;
use Phuppi\Queue\DeleteTransferStatsJob;

class QueueManager
{
    private string $mode;
    private int $maxConcurrent;
    private int $lockTimeoutMinutes = 5;

    public function __construct()
    {
        $this->mode = $this->getSetting('preview_queue_mode', 'cli');
        $this->maxConcurrent = (int) $this->getSetting('preview_max_concurrent', 5);
    }

    /**
     * Create preview job for file
     */
    public static function createJob(int $fileId): ?PreviewJob
    {
        $db = Flight::db();

        // Check if file type is supported for preview generation
        $stmt = $db->prepare('SELECT mimetype FROM uploaded_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            Flight::logger()->warning("Cannot create preview job: file $fileId not found");
            return null;
        }

        $mime = $row['mimetype'];
        $isSupported = str_starts_with($mime, 'image/') ||
                       str_starts_with($mime, 'video/') ||
                       $mime === 'application/pdf';

        if (!$isSupported) {
            // File type not supported for preview - mark as completed (no preview will be generated)
            $updateStmt = $db->prepare('UPDATE uploaded_files SET preview_status = "completed" WHERE id = ?');
            $updateStmt->execute([$fileId]);
            Flight::logger()->info("Skipped preview job for file $fileId: unsupported mime type $mime");
            return null;
        }

        $job = PreviewJob::createForFile($fileId);
        Flight::logger()->info("Created preview job {$job->id} for file $fileId");
        return $job;
    }

    /**
     * Create video preview job for file
     */
    public static function createVideoPreviewJob(int $fileId): VideoPreviewJob
    {
        $job = VideoPreviewJob::createForFile($fileId);
        Flight::logger()->info("Created video preview job {$job->id} for file $fileId");
        return $job;
    }

    /**
     * Create delete transfer stats job for file
     */
    public static function createDeleteTransferStatsJob(int $fileId): DeleteTransferStatsJob
    {
        $job = DeleteTransferStatsJob::createForFile($fileId);
        Flight::logger()->info("Created delete transfer stats job {$job->id} for file $fileId");
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
                
                // Try to claim job and acquire lock (process smallest files first)
                $stmt = $db->prepare('
                    SELECT pj.id FROM preview_jobs pj
                    INNER JOIN uploaded_files uf ON uf.id = pj.uploaded_file_id
                    WHERE pj.status = "pending"
                    AND NOT EXISTS (
                        SELECT 1 FROM queue_locks ql
                        WHERE ql.job_id = pj.id
                        AND ql.expires_at > datetime("now")
                    )
                    ORDER BY uf.filesize ASC, pj.created_at ASC
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
     * Claim next video preview job (CLI mode) with transaction handling and retry logic
     */
    public function claimNextVideoPreview(int $maxRetries = 3): ?VideoPreviewJob
    {
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                $db = Flight::db();
                
                // Begin immediate transaction for SQLite locking
                $db->exec('BEGIN IMMEDIATE');
                
                // Cleanup expired locks first
                $this->cleanupExpiredLocks($db);
                
                // Try to claim video preview job and acquire lock (process smallest files first)
                $stmt = $db->prepare('
                    SELECT vj.id FROM video_preview_jobs vj
                    INNER JOIN uploaded_files uf ON uf.id = vj.uploaded_file_id
                    WHERE vj.status = "pending"
                    AND NOT EXISTS (
                        SELECT 1 FROM queue_locks ql
                        WHERE ql.job_id = vj.id
                        AND ql.expires_at > datetime("now")
                    )
                    ORDER BY uf.filesize ASC, vj.created_at ASC
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
                    UPDATE video_preview_jobs
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
                $job = VideoPreviewJob::findById($jobId);
                if ($job) {
                    Flight::logger()->info("Claimed video preview job {$job->id} for file {$job->uploaded_file_id}");
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
                    Flight::logger()->error("Failed to claim video preview job after {$attempt} attempts: " . $e->getMessage());
                    return null;
                }
                
                // Wait before retry (exponential backoff)
                usleep(50000 * $attempt); // 50ms, 100ms, 150ms...
            }
        }
        
        return null;
    }

    /**
     * Claim next delete transfer stats job (CLI mode) with transaction handling and retry logic
     */
    public function claimNextDeleteTransferStatsJob(int $maxRetries = 3): ?DeleteTransferStatsJob
    {
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                $db = Flight::db();
                
                // Begin immediate transaction for SQLite locking
                $db->exec('BEGIN IMMEDIATE');
                
                // Cleanup expired locks first
                $this->cleanupExpiredLocks($db);
                
                // Try to claim delete transfer stats job and acquire lock (oldest first)
                $stmt = $db->prepare('
                    SELECT dtj.id FROM delete_transfer_stats_jobs dtj
                    WHERE dtj.status = "pending"
                    AND NOT EXISTS (
                        SELECT 1 FROM queue_locks ql
                        WHERE ql.job_id = dtj.id
                        AND ql.expires_at > datetime("now")
                    )
                    ORDER BY dtj.created_at ASC
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
                    UPDATE delete_transfer_stats_jobs
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
                $job = DeleteTransferStatsJob::findById($jobId);
                if ($job) {
                    Flight::logger()->info("Claimed delete transfer stats job {$job->id} for file {$job->uploaded_file_id}");
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
                    Flight::logger()->error("Failed to claim delete transfer stats job after {$attempt} attempts: " . $e->getMessage());
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
     * Process single video preview job
     */
    public function processVideoPreviewJob(VideoPreviewJob $job): bool
    {
        try {
            // Check if the file is a video
            $file = UploadedFile::findById($job->uploaded_file_id);
            if (!$file) {
                $job->markFailed('File not found');
                return false;
            }

            if (!str_starts_with($file->mimetype, 'video/')) {
                $job->markFailed('File is not a video');
                return false;
            }

            $generator = new VideoPreviewGenerator();
            $success = $generator->generate($job->uploaded_file_id, true); // Skip permission check for queue workers

            if ($success) {
                $job->markComplete();
            } else {
                $job->markFailed('Video preview generation failed');
            }
            return $success;
        } catch (Exception $e) {
            Flight::logger()->error("Video preview job {$job->id} failed: " . $e->getMessage());
            $job->markFailed($e->getMessage());
            return false;
        }
    }

    /**
     * Process batch of video preview jobs (AJAX mode)
     */
    public function processVideoPreviewBatch(int $limit = 0): array
    {
        $limit = $limit ?: $this->maxConcurrent;
        $results = [];
        $processed = 0;

        while ($processed < $limit) {
            $job = $this->claimNextVideoPreview();
            if (!$job) break;

            $success = $this->processVideoPreviewJob($job);
            $results[] = [
                'job_id' => $job->id,
                'file_id' => $job->uploaded_file_id,
                'success' => $success
            ];

            $processed++;
        }

        $hasMore = $this->hasPendingVideoPreviewJobs();
        return [
            'processed' => $processed,
            'has_more' => $hasMore,
            'results' => $results
        ];
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
        // Also cleanup video preview jobs
        $db->exec('
            UPDATE video_preview_jobs
            SET status = "failed", last_error = "Lock expired - job timed out", processed_at = datetime("now")
            WHERE status = "processing"
            AND id IN (SELECT job_id FROM queue_locks WHERE expires_at < datetime("now"))
        ');
        // Also cleanup delete transfer stats jobs
        $db->exec('
            UPDATE delete_transfer_stats_jobs
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
     * Check if pending video preview jobs exist
     */
    public function hasPendingVideoPreviewJobs(): bool
    {
        $db = Flight::db();
        $count = $db->query('SELECT COUNT(*) FROM video_preview_jobs WHERE status = "pending"')->fetchColumn();
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
