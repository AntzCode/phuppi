<?php
/**
 * PreviewJob.php
 *
 * Represents a preview generation job in the queue.
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
use PDO;

class PreviewJob
{
    public int $id;
    public int $uploaded_file_id;
    public string $status;
    public int $attempts;
    public ?string $last_error;
    public string $created_at;
    public ?string $processed_at;

    /**
     * Create a new preview job for a file
     */
    public static function createForFile(int $fileId): PreviewJob
    {
        $db = Flight::db();
        
        $stmt = $db->prepare('
            INSERT INTO preview_jobs (uploaded_file_id, status, attempts, created_at)
            VALUES (?, ?, ?, ?)
        ');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$fileId, 'pending', 0, $now]);
        
        $job = new self();
        $job->id = (int) $db->lastInsertId();
        $job->uploaded_file_id = $fileId;
        $job->status = 'pending';
        $job->attempts = 0;
        $job->last_error = null;
        $job->created_at = $now;
        $job->processed_at = null;
        
        return $job;
    }

    /**
     * Find a job by ID
     */
    public static function findById(int $id): ?PreviewJob
    {
        $db = Flight::db();
        
        $stmt = $db->prepare('SELECT * FROM preview_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        return self::fromRow($row);
    }

    /**
     * Create a PreviewJob from a database row
     */
    private static function fromRow(array $row): PreviewJob
    {
        $job = new self();
        $job->id = (int) $row['id'];
        $job->uploaded_file_id = (int) $row['uploaded_file_id'];
        $job->status = $row['status'];
        $job->attempts = (int) $row['attempts'];
        $job->last_error = $row['last_error'];
        $job->created_at = $row['created_at'];
        $job->processed_at = $row['processed_at'];
        
        return $job;
    }

    /**
     * Mark the job as completed
     */
    public function markComplete(): void
    {
        $db = Flight::db();
        
        $stmt = $db->prepare("
            UPDATE preview_jobs
            SET status = 'completed', processed_at = ?
            WHERE id = ?
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $this->id]);
        
        // Also update the uploaded_files preview_status to completed
        $fileStmt = $db->prepare("
            UPDATE uploaded_files
            SET preview_status = 'completed'
            WHERE id = ?
        ");
        $fileStmt->execute([$this->uploaded_file_id]);
        
        $this->status = 'completed';
        $this->processed_at = date('Y-m-d H:i:s');
    }

    /**
     * Mark the job as failed with an error message
     */
    public function markFailed(string $error): void
    {
        $db = Flight::db();
        
        $stmt = $db->prepare("
            UPDATE preview_jobs
            SET status = 'failed', last_error = ?, processed_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$error, date('Y-m-d H:i:s'), $this->id]);
        
        // Also update the uploaded_files preview_status to failed
        $fileStmt = $db->prepare("
            UPDATE uploaded_files
            SET preview_status = 'failed'
            WHERE id = ?
        ");
        $fileStmt->execute([$this->uploaded_file_id]);
        
        $this->status = 'failed';
        $this->last_error = $error;
        $this->processed_at = date('Y-m-d H:i:s');
    }
}