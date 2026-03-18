<?php
/**
 * DeleteTransferStatsJob.php
 *
 * Represents a delete transfer stats job in the queue.
 *
 * @package Phuppi\Queue
 * @author Anthony Gallon, Owner/Licensor: AntzCode Ltd <https://www.antzcode.com>, Contact: https://github.com/AntzCode
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 */

namespace Phuppi\Queue;

use Flight;
use PDO;

class DeleteTransferStatsJob
{
    public int $id;
    public int $uploaded_file_id;
    public string $status;
    public int $attempts;
    public ?string $last_error;
    public string $created_at;
    public ?string $processed_at;

    /**
     * Create a new delete transfer stats job for a file
     */
    public static function createForFile(int $fileId): DeleteTransferStatsJob
    {
        $db = Flight::db();
        
        $stmt = $db->prepare('
            INSERT INTO delete_transfer_stats_jobs (uploaded_file_id, status, attempts, created_at)
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
        
        Flight::logger()->info('Created delete transfer stats job', ['job_id' => $job->id, 'file_id' => $fileId]);
        
        return $job;
    }

    /**
     * Find a job by ID
     */
    public static function findById(int $id): ?DeleteTransferStatsJob
    {
        $db = Flight::db();
        
        $stmt = $db->prepare('SELECT * FROM delete_transfer_stats_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        return self::fromRow($row);
    }

    /**
     * Create a DeleteTransferStatsJob from a database row
     */
    private static function fromRow(array $row): DeleteTransferStatsJob
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
    public function markComplete(): self
    {
        $db = Flight::db();
        
        $stmt = $db->prepare("
            UPDATE delete_transfer_stats_jobs
            SET status = 'completed', processed_at = ?
            WHERE id = ?
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $this->id]);
        
        $this->status = 'completed';
        $this->processed_at = date('Y-m-d H:i:s');
        
        Flight::logger()->info('Marked delete transfer stats job as completed', ['job_id' => $this->id]);
        
        return $this;
    }

    /**
     * Mark the job as failed with an error message
     */
    public function markFailed(string $error): self
    {
        $db = Flight::db();
        
        $stmt = $db->prepare("
            UPDATE delete_transfer_stats_jobs
            SET status = 'failed', last_error = ?, processed_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$error, date('Y-m-d H:i:s'), $this->id]);
        
        $this->status = 'failed';
        $this->last_error = $error;
        $this->processed_at = date('Y-m-d H:i:s');
        
        Flight::logger()->error('Marked delete transfer stats job as failed', ['job_id' => $this->id, 'error' => $error]);
        
        return $this;
    }
}
