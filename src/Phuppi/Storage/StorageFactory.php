<?php

/**
 * StorageFactory.php
 *
 * StorageFactory class for managing file storage in the Phuppi application.
 *
 * @package Phuppi\Storage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Storage;

use Flight;
use Phuppi\Service\TransferStats;

class StorageFactory
{
    /**
     * Get the active storage connector
     * 
     * @return StorageInterface
     */
    public static function create(): StorageInterface
    {
        $connectors = Flight::get('storage_connectors') ?? [];
        $activeConnector = Flight::get('active_storage_connector') ?? 'default';

        if (!isset($connectors[$activeConnector])) {
            // Fallback to default local storage
            return new LocalStorage();
        }

        $config = $connectors[$activeConnector];
        return self::createConnector($config['type'], $config);
    }

    /**
     * Get a specific connector by name
     * 
     * @param string $name The name of the connector to get
     * @return StorageInterface
     */
    public static function createConnectorByName(string $name): StorageInterface
    {
        $connectors = Flight::get('storage_connectors') ?? [];

        if (!isset($connectors[$name])) {
            throw new \InvalidArgumentException("Storage connector '$name' not found");
        }

        $config = $connectors[$name];
        return self::createConnector($config['type'], $config);
    }

    /**
     * Create a connector instance
     *
     * @param string $type The type of connector to create
     * @param array $config The configuration for the connector
     * @return StorageInterface
     */
    private static function createConnector(string $type, array $config): StorageInterface
    {
        switch ($type) {
            case 'local':
                return new LocalStorage($config);
            case 's3':
            case 'do_spaces':
                return new S3Storage($config);
            default:
                throw new \InvalidArgumentException("Unsupported storage type: $type");
        }
    }

    /**
     * Records transfer statistics for migration operations.
     *
     * @param \Phuppi\UploadedFile $file The file being migrated.
     * @param string $connectorName The connector name.
     * @param string $direction Direction of transfer (TransferStats::DIRECTION_INGRESS or EGRESS).
     * @param int $bytesTransferred Bytes transferred.
     * @return void
     */
    private static function recordMigrationStats(\Phuppi\UploadedFile $file, string $connectorName, string $direction, int $bytesTransferred): void
    {
        try {
            $transferStats = new TransferStats();

            if ($direction === TransferStats::DIRECTION_INGRESS) {
                $transferStats->recordIngress(
                    $connectorName,
                    $file->id,
                    $file->user_id,
                    $file->voucher_id,
                    TransferStats::OPERATION_MIGRATION_INGRESS,
                    $bytesTransferred
                );
            } else {
                $transferStats->recordEgress(
                    $connectorName,
                    $file->id,
                    $file->user_id,
                    $file->voucher_id,
                    TransferStats::OPERATION_MIGRATION_EGRESS,
                    $bytesTransferred
                );
            }
        } catch (\Exception $e) {
            // Log but don't fail migration if stats recording fails
            Flight::logger()->warning('Failed to record migration transfer stats: ' . $e->getMessage());
        }
    }

    /**
     * Get list of available connectors
     * 
     * @return array
     */
    public static function getConnectors(): array
    {
        return Flight::get('storage_connectors') ?? [];
    }

    /**
     * Get active connector name
     * 
     * @return string
     */
    public static function getActiveConnector(): string
    {
        return Flight::get('active_storage_connector') ?? 'default';
    }

    /**
     * Migrate files from one connector to another
     *
     * @param string $fromConnector The name of the connector to migrate from
     * @param string $toConnector The name of the connector to migrate to
     * @param array|null $fileIds The IDs of the files to migrate
     * @param float $transferLimitGb Transfer limit in GB (0 = no limit)
     * @param string $transferPriority Priority for sorting: 'smallest_first', 'largest_first', 'newest_first', 'oldest_first'
     * @return array
     */
    public static function migrate(string $fromConnector, string $toConnector, ?array $fileIds=null, float $transferLimitGb = 0, string $transferPriority = 'smallest_first'): array
    {
        $fromStorage = self::createConnectorByName($fromConnector);
        $toStorage = self::createConnectorByName($toConnector);

        // Convert GB to bytes
        $limitBytes = $transferLimitGb * 1024 * 1024 * 1024;

        // Get files to migrate
        if ($fileIds === null) {
            // Migrate all files
            $result = \Phuppi\UploadedFile::findFiltered(null, null, '', 'date_newest', PHP_INT_MAX, 0);
            $files = $result['files'];
        } else {
            $files = array_map(fn($id) => \Phuppi\UploadedFile::findById($id), $fileIds);
            $files = array_filter($files); // Remove nulls
        }

        // Sort files by priority
        usort($files, function ($a, $b) use ($transferPriority) {
            switch ($transferPriority) {
                case 'largest_first':
                    return $b->filesize <=> $a->filesize;
                case 'newest_first':
                    return strtotime($b->uploaded_at) <=> strtotime($a->uploaded_at);
                case 'oldest_first':
                    return strtotime($a->uploaded_at) <=> strtotime($b->uploaded_at);
                case 'smallest_first':
                default:
                    return $a->filesize <=> $b->filesize;
            }
        });

        $totalSize = array_sum(array_map(fn($f) => $f->filesize, $files));
        $processedSize = 0;
        $startTime = microtime(true);
        $currentFile = null;
        $currentSize = 0;
        $limitReached = false;

        $results = [
            'migrated' => 0,
            'skipped' => 0,
            'errors' => [],
            'total_size' => $totalSize,
            'processed_size' => 0,
            'bytes_transferred' => 0,  // Track bytes actually transferred from source
            'current_file' => null,
            'current_size' => 0,
            'eta' => 0,
            'limit_reached' => false,
            'limit_bytes' => $limitBytes,
            'limit_bytes_remaining' => $limitBytes
        ];

        foreach ($files as $file) {

            $currentFile = $file->display_filename;
            $currentSize = $file->filesize;

            Flight::logger()->info("Migrating file: {$file->display_filename} ({$file->filesize} bytes)");
            
            try {
                $filePath = $file->getUsername() . '/' . $file->filename;

                // Check if file exists in source
                if (!$fromStorage->exists($filePath)) {
                    $results['errors'][] = "File {$filePath} not found in source connector";
                    continue;
                }

                // Check if file already exists in destination with same size
                if ($toStorage->exists($filePath)) {

                    $sourceSize = $fromStorage->size($filePath);
                    $destSize = $toStorage->size($filePath);

                    if ($sourceSize !== null && $destSize !== null && $sourceSize === $destSize) {
                        $results['skipped']++;
                        // Skipped files don't count toward limit
                        continue;
                    }
                }

                // Check if adding this file would exceed the limit (if limit is set)
                if ($limitBytes > 0 && ($processedSize + $file->filesize) > $limitBytes) {
                    $limitReached = true;
                    $results['limit_reached'] = true;
                    $results['limit_bytes_remaining'] = max(0, $limitBytes - $processedSize);
                    break;
                }

                // Get file stream from source
                $stream = $fromStorage->getStream($filePath);
                if (!$stream) {
                    $results['errors'][] = "Could not read file {$filePath} from source connector";
                    continue;
                }

                // Create temp file for transfer
                $tempPath = tempnam(sys_get_temp_dir(), 'migration_');
                $tempHandle = fopen($tempPath, 'w');
                $streamClosed = false;
                $bytesTransferred = 0;  // Track actual bytes read from source

                if (is_resource($stream)) {

                    $bytesTransferred = stream_copy_to_stream($stream, $tempHandle);
                    fclose($stream);
                    $streamClosed = true;

                } elseif (method_exists($stream, 'detach')) {
                    // Psr7 stream, detach to get resource

                    $resource = $stream->detach();

                    if (is_resource($resource)) {
                        $bytesTransferred = stream_copy_to_stream($resource, $tempHandle);
                        fclose($resource);
                        $streamClosed = true;
                    } else {
                        $results['errors'][] = "Could not detach stream for file {$filePath}";
                        fclose($tempHandle);
                        unlink($tempPath);
                        continue;
                    }

                } elseif (method_exists($stream, 'getContents')) {
                    // Fallback for objects, but try to avoid memory issues
                    $contents = $stream->getContents();
                    $bytesTransferred = strlen($contents);
                    fwrite($tempHandle, $contents);

                } else {
                    $results['errors'][] = "Unsupported stream type for file {$filePath}";
                    fclose($tempHandle);
                    unlink($tempPath);
                    continue;
                }

                fclose($tempHandle);

                // Ensure we have a valid byte count (fallback to file size if needed)
                if ($bytesTransferred === 0 && $file->filesize > 0) {
                    $bytesTransferred = $file->filesize;
                }

                // Record ingress transfer (reading from source connector)
                // This counts toward the transfer limit regardless of whether the full transfer succeeds
                self::recordMigrationStats($file, $fromConnector, TransferStats::DIRECTION_INGRESS, $bytesTransferred);

                // Put file to destination
                if (!$toStorage->put($filePath, $tempPath)) {
                    // Transfer failed - but we still read bytes from source, so count them toward limit
                    $results['errors'][] = "Could not write file {$filePath} to destination connector";
                    $results['bytes_transferred'] += $bytesTransferred;
                    $results['processed_size'] += $bytesTransferred;
                    $results['limit_bytes_remaining'] = $limitBytes > 0 ? max(0, $limitBytes - $results['processed_size']) : 0;
                    unlink($tempPath);
                    continue;
                }

                // Record egress transfer (writing to destination connector)
                self::recordMigrationStats($file, $toConnector, TransferStats::DIRECTION_EGRESS, $bytesTransferred);

                // Clean up temp file
                unlink($tempPath);

                // Also migrate preview image if it exists
                self::migratePreviewImage($file, $fromStorage, $toStorage, $results);

                // Also migrate video preview if it exists (and count its size toward limit)
                $videoPreviewSize = self::migrateVideoPreview($file, $fromStorage, $toStorage, $results);

                // Update file record with new connector (if we add a field for it)
                // For now, just count as migrated
                $results['migrated']++;
                $results['bytes_transferred'] += $bytesTransferred + $videoPreviewSize;
                $processedSize += $bytesTransferred + $videoPreviewSize;
                $results['limit_bytes_remaining'] = $limitBytes > 0 ? max(0, $limitBytes - $processedSize) : 0;
                
            } catch (\Exception $e) {
                $results['errors'][] = "Error migrating {$file->filename}: " . $e->getMessage();
                // Clean up temp file if it exists and wasn't already cleaned up
                if (isset($tempPath) && file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }

        $elapsed = microtime(true) - $startTime;
        $progress = $totalSize > 0 ? $processedSize / $totalSize : 1;
        $results['eta'] = $progress > 0 ? ($elapsed / $progress) - $elapsed : 0;
        $results['processed_size'] = $processedSize;
        $results['current_file'] = $currentFile;
        $results['current_size'] = $currentSize;

        return $results;
    }

    /**
     * Migrate preview image for a file
     *
     * @param \Phuppi\UploadedFile $file The file to migrate preview for
     * @param StorageInterface $fromStorage Source storage
     * @param StorageInterface $toStorage Destination storage
     * @param array $results Results array to update
     * @return void
     */
    private static function migratePreviewImage(\Phuppi\UploadedFile $file, StorageInterface $fromStorage, StorageInterface $toStorage, array &$results): void
    {
        if (empty($file->preview_filename)) {
            return;
        }

        $username = $file->getUsername();
        $previewPath = $username . '/previews/' . $file->preview_filename;

        // Check if preview exists in source
        if (!$fromStorage->exists($previewPath)) {
            return;
        }

        // Check if preview already exists in destination
        if ($toStorage->exists($previewPath)) {
            $sourceSize = $fromStorage->size($previewPath);
            $destSize = $toStorage->size($previewPath);
            if ($sourceSize !== null && $destSize !== null && $sourceSize === $destSize) {
                return; // Already exists with same size
            }
        }

        // Get preview stream from source
        $stream = $fromStorage->getStream($previewPath);
        if (!$stream) {
            $results['errors'][] = "Could not read preview {$previewPath} from source connector";
            return;
        }

        // Create temp file for transfer
        $tempPath = tempnam(sys_get_temp_dir(), 'preview_migration_');
        $tempHandle = fopen($tempPath, 'w');

        if (is_resource($stream)) {
            stream_copy_to_stream($stream, $tempHandle);
            fclose($stream);
        } elseif (method_exists($stream, 'detach')) {
            $resource = $stream->detach();
            if (is_resource($resource)) {
                stream_copy_to_stream($resource, $tempHandle);
                fclose($resource);
            } else {
                fclose($tempHandle);
                unlink($tempPath);
                return;
            }
        } elseif (method_exists($stream, 'getContents')) {
            fwrite($tempHandle, $stream->getContents());
        } else {
            fclose($tempHandle);
            unlink($tempPath);
            return;
        }

        fclose($tempHandle);

        // Put preview to destination
        if (!$toStorage->put($previewPath, $tempPath)) {
            $results['errors'][] = "Could not write preview {$previewPath} to destination connector";
            unlink($tempPath);
            return;
        }

        unlink($tempPath);
    }

    /**
     * Migrate video preview for a file
     *
     * @param \Phuppi\UploadedFile $file The file to migrate video preview for
     * @param StorageInterface $fromStorage Source storage
     * @param StorageInterface $toStorage Destination storage
     * @param array $results Results array to update
     * @return int Total size of video previews migrated (for limit counting)
     */
    private static function migrateVideoPreview(\Phuppi\UploadedFile $file, StorageInterface $fromStorage, StorageInterface $toStorage, array &$results): int
    {
        $totalSize = 0;

        // Migrate video preview MP4 file
        if (!empty($file->video_preview_filename)) {
            $totalSize += self::migrateSingleVideoPreview($file, $fromStorage, $toStorage, $results, 'video_preview_filename', 'video-previews');
        }

        // Migrate video preview poster image if it exists
        if (!empty($file->video_preview_poster_filename)) {
            $totalSize += self::migrateSingleVideoPreview($file, $fromStorage, $toStorage, $results, 'video_preview_poster_filename', 'video-previews');
        }

        return $totalSize;
    }

    /**
     * Migrate a single video preview file (MP4 or poster)
     *
     * @param \Phuppi\UploadedFile $file The file to migrate preview for
     * @param StorageInterface $fromStorage Source storage
     * @param StorageInterface $toStorage Destination storage
     * @param array $results Results array to update
     * @param string $fieldName The field name (video_preview_filename or video_preview_poster_filename)
     * @param string $folder The folder name (video-previews)
     * @return int Size of the file that was migrated (0 if skipped or failed)
     */
    private static function migrateSingleVideoPreview(\Phuppi\UploadedFile $file, StorageInterface $fromStorage, StorageInterface $toStorage, array &$results, string $fieldName, string $folder): int
    {
        $filename = $file->$fieldName;
        if (empty($filename)) {
            return 0;
        }

        $username = $file->getUsername();
        $previewPath = $username . '/' . $folder . '/' . $filename;

        // Check if preview exists in source
        if (!$fromStorage->exists($previewPath)) {
            return 0;
        }

        // Get source size for counting toward limit
        $sourceSize = $fromStorage->size($previewPath);
        if ($sourceSize === null) {
            $sourceSize = 0;
        }

        // Check if preview already exists in destination
        if ($toStorage->exists($previewPath)) {
            $destSize = $toStorage->size($previewPath);
            if ($sourceSize !== 0 && $destSize !== null && $sourceSize === $destSize) {
                return 0; // Already exists with same size - don't count toward limit
            }
        }

        // Get preview stream from source
        $stream = $fromStorage->getStream($previewPath);
        if (!$stream) {
            $results['errors'][] = "Could not read video preview {$previewPath} from source connector";
            return 0;
        }

        // Create temp file for transfer
        $tempPath = tempnam(sys_get_temp_dir(), 'video_preview_migration_');
        $tempHandle = fopen($tempPath, 'w');

        if (is_resource($stream)) {
            stream_copy_to_stream($stream, $tempHandle);
            fclose($stream);
        } elseif (method_exists($stream, 'detach')) {
            $resource = $stream->detach();
            if (is_resource($resource)) {
                stream_copy_to_stream($resource, $tempHandle);
                fclose($resource);
            } else {
                fclose($tempHandle);
                unlink($tempPath);
                return 0;
            }
        } elseif (method_exists($stream, 'getContents')) {
            fwrite($tempHandle, $stream->getContents());
        } else {
            fclose($tempHandle);
            unlink($tempPath);
            return 0;
        }

        fclose($tempHandle);

        // Put preview to destination
        if (!$toStorage->put($previewPath, $tempPath)) {
            $results['errors'][] = "Could not write video preview {$previewPath} to destination connector";
            unlink($tempPath);
            return 0;
        }

        unlink($tempPath);

        // Return the size of the migrated file for limit counting
        return $sourceSize;
    }
}
