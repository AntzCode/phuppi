<?php

namespace Phuppi\Storage;

use Flight;

class StorageFactory
{
    /**
     * Get the active storage connector
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
     */
    private static function createConnector(string $type, array $config): StorageInterface
    {
        switch ($type) {
            case 'local':
                return new LocalStorage($config);
            case 's3':
                return new S3Storage($config);
            default:
                throw new \InvalidArgumentException("Unsupported storage type: $type");
        }
    }

    /**
     * Get list of available connectors
     */
    public static function getConnectors(): array
    {
        return Flight::get('storage_connectors') ?? [];
    }

    /**
     * Get active connector name
     */
    public static function getActiveConnector(): string
    {
        return Flight::get('active_storage_connector') ?? 'default';
    }

    /**
     * Migrate files from one connector to another
     */
    public static function migrate(string $fromConnector, string $toConnector, array $fileIds = null): array
    {
        $fromStorage = self::createConnectorByName($fromConnector);
        $toStorage = self::createConnectorByName($toConnector);

        // Get files to migrate
        if ($fileIds === null) {
            // Migrate all files
            $result = \Phuppi\UploadedFile::findFiltered(null, null, '', 'date_newest', PHP_INT_MAX, 0);
            $files = $result['files'];
        } else {
            $files = array_map(fn($id) => \Phuppi\UploadedFile::findById($id), $fileIds);
            $files = array_filter($files); // Remove nulls
        }

        // Sort files by size ascending (smallest first)
        usort($files, function($a, $b) {
            return $a->filesize <=> $b->filesize;
        });

        $totalSize = array_sum(array_map(fn($f) => $f->filesize, $files));
        $processedSize = 0;
        $startTime = microtime(true);
        $currentFile = null;
        $currentSize = 0;

        $results = ['migrated' => 0, 'skipped' => 0, 'errors' => [], 'total_size' => $totalSize, 'processed_size' => 0, 'current_file' => null, 'current_size' => 0, 'eta' => 0];

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
                        $processedSize += $file->filesize;
                        continue;
                    }
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
                if (is_resource($stream)) {
                    stream_copy_to_stream($stream, $tempHandle);
                    fclose($stream);
                    $streamClosed = true;
                } elseif (method_exists($stream, 'detach')) {
                    // Psr7 stream, detach to get resource
                    $resource = $stream->detach();
                    if (is_resource($resource)) {
                        stream_copy_to_stream($resource, $tempHandle);
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
                    fwrite($tempHandle, $stream->getContents());
                } else {
                    $results['errors'][] = "Unsupported stream type for file {$filePath}";
                    fclose($tempHandle);
                    unlink($tempPath);
                    continue;
                }
                fclose($tempHandle);

                // Put file to destination
                if (!$toStorage->put($filePath, $tempPath)) {
                    $results['errors'][] = "Could not write file {$filePath} to destination connector";
                    unlink($tempPath);
                    continue;
                }

                // Clean up temp file
                unlink($tempPath);

                // Update file record with new connector (if we add a field for it)
                // For now, just count as migrated
                $results['migrated']++;
                $processedSize += $file->filesize;

            } catch (\Exception $e) {
                $results['errors'][] = "Error migrating {$file->filename}: " . $e->getMessage();
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
}