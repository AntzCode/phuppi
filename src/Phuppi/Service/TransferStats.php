<?php
/**
 * TransferStats.php
 *
 * Service class for recording storage transfer statistics.
 *
 * @package Phuppi\Service
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

namespace Phuppi\Service;

use Flight;
use PDO;

class TransferStats
{
    /**
     * Valid operation types for transfer stats
     */
    public const OPERATION_UPLOAD = 'upload';
    public const OPERATION_DOWNLOAD = 'download';
    public const OPERATION_STREAM = 'stream';
    public const OPERATION_PREVIEW_GENERATE_INGRESS = 'preview_generate_ingress';
    public const OPERATION_PREVIEW_GENERATE_EGRESS = 'preview_generate_egress';
    public const OPERATION_PREVIEW_SERVE = 'preview_serve';
    public const OPERATION_MIGRATION_INGRESS = 'migration_ingress';
    public const OPERATION_MIGRATION_EGRESS = 'migration_egress';

    /**
     * Valid directions for transfer stats
     */
    public const DIRECTION_INGRESS = 'ingress';
    public const DIRECTION_EGRESS = 'egress';

    /**
     * List of valid operation types
     */
    private const VALID_OPERATION_TYPES = [
        self::OPERATION_UPLOAD,
        self::OPERATION_DOWNLOAD,
        self::OPERATION_STREAM,
        self::OPERATION_PREVIEW_GENERATE_INGRESS,
        self::OPERATION_PREVIEW_GENERATE_EGRESS,
        self::OPERATION_PREVIEW_SERVE,
        self::OPERATION_MIGRATION_INGRESS,
        self::OPERATION_MIGRATION_EGRESS,
    ];

    /**
     * List of valid directions
     */
    private const VALID_DIRECTIONS = [
        self::DIRECTION_INGRESS,
        self::DIRECTION_EGRESS,
    ];

    /**
     * Record a transfer statistics entry
     *
     * @param string $connectorName Name of the storage connector (e.g., 'local-default', 'minio-default')
     * @param int|null $fileId The uploaded_file id
     * @param int|null $userId The user id if applicable
     * @param int|null $voucherId The voucher id if applicable
     * @param string $direction Direction of transfer: 'ingress' or 'egress'
     * @param string $operationType Type of operation: 'upload', 'download', 'stream', 'preview_generate_ingress', 'preview_generate_egress', 'preview_serve', 'migration_ingress', 'migration_egress'
     * @param int $bytesTransferred Actual bytes transferred
     * @return bool Success
     */
    public function record(
        string $connectorName,
        ?int $fileId,
        ?int $userId,
        ?int $voucherId,
        string $direction,
        string $operationType,
        int $bytesTransferred
    ): bool {
        // Validate direction
        if (!in_array($direction, self::VALID_DIRECTIONS, true)) {
            Flight::logger()->warning("TransferStats: Invalid direction '$direction'");
            return false;
        }

        // Validate operation type
        if (!in_array($operationType, self::VALID_OPERATION_TYPES, true)) {
            Flight::logger()->warning("TransferStats: Invalid operation type '$operationType'");
            return false;
        }

        // Validate bytes transferred
        if ($bytesTransferred < 0) {
            Flight::logger()->warning("TransferStats: Invalid bytes transferred '$bytesTransferred'");
            return false;
        }

        try {
            $db = Flight::transferStatsDb();

            $stmt = $db->prepare('
                INSERT INTO transfer_stats (
                    connector_name,
                    file_id,
                    user_id,
                    voucher_id,
                    direction,
                    operation_type,
                    bytes_transferred
                ) VALUES (
                    :connector_name,
                    :file_id,
                    :user_id,
                    :voucher_id,
                    :direction,
                    :operation_type,
                    :bytes_transferred
                )
            ');

            $stmt->execute([
                ':connector_name' => $connectorName,
                ':file_id' => $fileId,
                ':user_id' => $userId,
                ':voucher_id' => $voucherId,
                ':direction' => $direction,
                ':operation_type' => $operationType,
                ':bytes_transferred' => $bytesTransferred,
            ]);

            Flight::logger()->debug("TransferStats: Recorded {$bytesTransferred} bytes {$direction} for connector '$connectorName' operation '$operationType'");
            return true;

        } catch (\PDOException $e) {
            Flight::logger()->error("TransferStats: Failed to record transfer - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the current active storage connector name
     *
     * @return string The active storage connector name (e.g., 'local-default', 'minio-default')
     */
    public function getCurrentConnectorName(): string
    {
        return Flight::get('active_storage_connector') ?? 'local-default';
    }

    /**
     * Record an ingress (upload) transfer
     *
     * @param string $connectorName Name of the storage connector
     * @param int|null $fileId The uploaded_file id
     * @param int|null $userId The user id if applicable
     * @param int|null $voucherId The voucher id if applicable
     * @param string $operationType Type of operation
     * @param int $bytesTransferred Actual bytes transferred
     * @return bool Success
     */
    public function recordIngress(
        string $connectorName,
        ?int $fileId,
        ?int $userId,
        ?int $voucherId,
        string $operationType,
        int $bytesTransferred
    ): bool {
        return $this->record(
            $connectorName,
            $fileId,
            $userId,
            $voucherId,
            self::DIRECTION_INGRESS,
            $operationType,
            $bytesTransferred
        );
    }

    /**
     * Record an egress (download) transfer
     *
     * @param string $connectorName Name of the storage connector
     * @param int|null $fileId The uploaded_file id
     * @param int|null $userId The user id if applicable
     * @param int|null $voucherId The voucher id if applicable
     * @param string $operationType Type of operation
     * @param int $bytesTransferred Actual bytes transferred
     * @return bool Success
     */
    public function recordEgress(
        string $connectorName,
        ?int $fileId,
        ?int $userId,
        ?int $voucherId,
        string $operationType,
        int $bytesTransferred
    ): bool {
        return $this->record(
            $connectorName,
            $fileId,
            $userId,
            $voucherId,
            self::DIRECTION_EGRESS,
            $operationType,
            $bytesTransferred
        );
    }

    /**
     * Record a transfer using the current active connector
     *
     * @param int|null $fileId The uploaded_file id
     * @param int|null $userId The user id if applicable
     * @param int|null $voucherId The voucher id if applicable
     * @param string $direction Direction of transfer: 'ingress' or 'egress'
     * @param string $operationType Type of operation
     * @param int $bytesTransferred Actual bytes transferred
     * @return bool Success
     */
    public function recordWithCurrentConnector(
        ?int $fileId,
        ?int $userId,
        ?int $voucherId,
        string $direction,
        string $operationType,
        int $bytesTransferred
    ): bool {
        return $this->record(
            $this->getCurrentConnectorName(),
            $fileId,
            $userId,
            $voucherId,
            $direction,
            $operationType,
            $bytesTransferred
        );
    }

    /**
     * Get transfer statistics for a specific connector within a date range
     *
     * @param string $connectorName Name of the storage connector
     * @param \DateTime $startDate Start date (inclusive)
     * @param \DateTime $endDate End date (inclusive)
     * @return array Stats with 'ingress' and 'egress' keys containing total bytes
     */
    public function getStatsForConnector(string $connectorName, \DateTime $startDate, \DateTime $endDate): array
    {
        try {
            $db = Flight::transferStatsDb();

            $stmt = $db->prepare('
                SELECT direction, SUM(bytes_transferred) as total_bytes
                FROM transfer_stats
                WHERE connector_name = :connector_name
                    AND created_at >= :start_date
                    AND created_at <= :end_date
                GROUP BY direction
            ');

            $stmt->execute([
                ':connector_name' => $connectorName,
                ':start_date' => $startDate->format('Y-m-d H:i:s'),
                ':end_date' => $endDate->format('Y-m-d H:i:s'),
            ]);

            $results = [
                'ingress' => 0,
                'egress' => 0,
            ];

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $direction = $row['direction'];
                $totalBytes = (int) $row['total_bytes'];
                if (isset($results[$direction])) {
                    $results[$direction] = $totalBytes;
                }
            }

            return $results;

        } catch (\PDOException $e) {
            Flight::logger()->error("TransferStats: Failed to get stats - " . $e->getMessage());
            return [
                'ingress' => 0,
                'egress' => 0,
            ];
        }
    }

    /**
     * Get transfer statistics for all connectors within a date range
     *
     * @param \DateTime $startDate Start date (inclusive)
     * @param \DateTime $endDate End date (inclusive)
     * @return array Stats keyed by connector name, each containing 'ingress' and 'egress' totals
     */
    public function getStatsForAllConnectors(\DateTime $startDate, \DateTime $endDate): array
    {
        try {
            $db = Flight::transferStatsDb();

            $stmt = $db->prepare('
                SELECT connector_name, direction, SUM(bytes_transferred) as total_bytes
                FROM transfer_stats
                WHERE created_at >= :start_date
                    AND created_at <= :end_date
                GROUP BY connector_name, direction
            ');

            $stmt->execute([
                ':start_date' => $startDate->format('Y-m-d H:i:s'),
                ':end_date' => $endDate->format('Y-m-d H:i:s'),
            ]);

            $results = [];

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $connectorName = $row['connector_name'];
                $direction = $row['direction'];
                $totalBytes = (int) $row['total_bytes'];

                if (!isset($results[$connectorName])) {
                    $results[$connectorName] = [
                        'ingress' => 0,
                        'egress' => 0,
                    ];
                }

                if (isset($results[$connectorName][$direction])) {
                    $results[$connectorName][$direction] = $totalBytes;
                }
            }

            return $results;

        } catch (\PDOException $e) {
            Flight::logger()->error("TransferStats: Failed to get all stats - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete all transfer stats records associated with a specific file_id
     *
     * @param int $fileId The uploaded_file id
     * @return int Number of records deleted
     */
    public function deleteByFileId(int $fileId): int
    {
        $db = Flight::transferStatsDb();
        $stmt = $db->prepare('DELETE FROM transfer_stats WHERE file_id = ?');
        $stmt->execute([$fileId]);
        return $stmt->rowCount();
    }
}
