<?php

/**
 * SettingsController.php
 *
 * SettingsController class for managing application settings in the Phuppi application.
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\Helper;
use Phuppi\User;

class SettingsController
{
    /**
     * Displays the settings page.
     *
     * @return void
     */
    public function index(): void
    {
        $connectors = Flight::get('storage_connectors') ?? [];
        $activeConnector = Flight::get('active_storage_connector') ?? 'local-default';
        Flight::render('settings.latte', [
            'connectors' => $connectors,
            'activeConnector' => $activeConnector
        ]);
    }

    /**
     * Updates storage settings.
     *
     * @return void
     */
    public function updateStorage(): void
    {
        $data = Flight::request()->data;
        $action = $data->action ?? 'update_active';

        switch ($action) {
            case 'set_active':
                $this->setActiveConnector($data->connector_name ?? '');
                break;
            case 'add_connector':
                $this->addConnector($data);
                break;
            case 'update_connector':
                $this->updateConnector($data);
                break;
            case 'delete_connector':
                $this->deleteConnector($data->connector_name ?? '');
                break;
            case 'test_connection':
                $this->testConnection($data);
                return; // testConnection handles its own response
            case 'migrate':
                $this->migrateFiles($data);
                return; // migrateFiles handles its own response
            case 'get_migration_files':
                $this->getMigrationFiles($data);
                return; // getMigrationFiles handles its own response
            case 'scan_orphaned_records':
                $this->scanOrphanedRecords($data);
                return;
            case 'delete_orphaned_records':
                $this->deleteOrphanedRecords($data);
                return;
            case 'scan_orphaned_files':
                $this->scanOrphanedFiles($data);
                return;
            case 'import_orphaned_files':
                $this->importOrphanedFiles($data);
                return;
            default:
                Flight::json(['error' => 'Invalid action'], 400);
                return;
        }

        Flight::json(['message' => 'Storage settings updated']);
    }

    /**
     * Sets the active storage connector.
     *
     * @param string $connectorName The connector name.
     * @return void
     */
    private function setActiveConnector(string $connectorName): void
    {
        $connectors = Flight::get('storage_connectors') ?? [];
        if (!isset($connectors[$connectorName])) {
            Flight::json(['error' => 'Connector not found'], 404);
            return;
        }

        $db = Flight::db();
        $db->prepare('INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)')->execute(['active_storage_connector', $connectorName]);
        Flight::set('active_storage_connector', $connectorName);
    }

    /**
     * Adds a new storage connector.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function addConnector($data): void
    {
        $name = trim($data->connector_name ?? '');
        $type = $data->connector_type ?? '';
        $displayName = trim($data->connector_display_name ?? '');

        if (empty($name) || empty($type) || empty($displayName)) {
            Flight::json(['error' => 'Missing required fields'], 400);
            return;
        }

        $db = Flight::db();

        // Check if connector name already exists
        $stmt = $db->prepare('SELECT id FROM storage_connectors WHERE name = ?');
        $stmt->execute([$name]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            Flight::json(['error' => 'Connector name already exists'], 400);
            return;
        }

        $config = [
            'type' => $type,
            'name' => $displayName,
            'path_prefix' => trim($data->path_prefix ?? ''),
        ];

        // Add type-specific config
        switch ($type) {
            case 'local':
                $config['path'] = $data->local_path ?? null;
                break;
            case 's3':
                $config['bucket'] = $data->s3_bucket ?? '';
                $config['region'] = $data->s3_region ?? 'us-east-1';
                $config['key'] = $data->s3_key ?? '';
                $config['secret'] = $data->s3_secret ?? '';
                $config['endpoint'] = $data->s3_endpoint ?? null;
                break;
        }

        $db->prepare('INSERT INTO storage_connectors (name, type, config) VALUES (?, ?, ?)')->execute([
            $name,
            $type,
            json_encode($config)
        ]);

        // Reload connectors
        $this->reloadConnectors();
    }

    /**
     * Updates an existing storage connector.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function updateConnector($data): void
    {
        $name = $data->connector_name ?? '';
        $db = Flight::db();

        // Check if connector exists
        $stmt = $db->prepare('SELECT config FROM storage_connectors WHERE name = ?');
        $stmt->execute([$name]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$existing) {
            Flight::json(['error' => 'Connector not found'], 404);
            return;
        }

        $config = json_decode($existing['config'], true);
        $config['name'] = trim($data->connector_display_name ?? $config['name']);
        $config['path_prefix'] = trim($data->path_prefix ?? $config['path_prefix'] ?? '');

        // Update type-specific config
        switch ($config['type']) {
            case 'local':
                $config['path'] = $data->local_path ?? $config['path'];
                break;
            case 's3':
                $config['bucket'] = $data->s3_bucket ?? $config['bucket'];
                $config['region'] = $data->s3_region ?? $config['region'];
                $config['key'] = $data->s3_key ?? $config['key'];
                $config['secret'] = $data->s3_secret ?? $config['secret'];
                $config['endpoint'] = $data->s3_endpoint ?? $config['endpoint'];
                break;
        }

        $db->prepare('UPDATE storage_connectors SET config = ?, updated_at = CURRENT_TIMESTAMP WHERE name = ?')->execute([
            json_encode($config),
            $name
        ]);

        // Reload connectors
        $this->reloadConnectors();
    }

    /**
     * Deletes a storage connector.
     *
     * @param string $connectorName The connector name.
     * @return void
     */
    private function deleteConnector(string $connectorName): void
    {
        $db = Flight::db();
        $activeConnector = Flight::get('active_storage_connector');

        // Check if connector exists
        $stmt = $db->prepare('SELECT id FROM storage_connectors WHERE name = ?');
        $stmt->execute([$connectorName]);
        $existing = $stmt->fetchColumn();

        if (!$existing) {
            Flight::json(['error' => 'Connector not found'], 404);
            return;
        }

        if ($connectorName === 'local-default') {
            Flight::json(['error' => 'Cannot delete default connector'], 400);
            return;
        }

        if ($activeConnector === $connectorName) {
            Flight::json(['error' => 'Cannot delete active connector'], 400);
            return;
        }

        $db->prepare('DELETE FROM storage_connectors WHERE name = ?')->execute([$connectorName]);

        // Reload connectors
        $this->reloadConnectors();
    }

    /**
     * Migrates files between connectors.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function migrateFiles($data): void
    {
        $fromConnector = $data->from_connector ?? '';
        $toConnector = $data->to_connector ?? '';
        $fileIds = null;

        if (!empty($data->file_ids)) {
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $data->file_ids))));
            $fileIds = $ids;
        }

        if (empty($fromConnector) || empty($toConnector)) {
            Flight::json(['error' => 'Source and destination connectors required'], 400);
            return;
        }

        try {
            $results = \Phuppi\Storage\StorageFactory::migrate($fromConnector, $toConnector, $fileIds);
            Flight::json(['results' => $results]);
        } catch (\Exception $e) {
            Flight::json(['error' => 'Migration failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Gets files for migration.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function getMigrationFiles($data): void
    {
        $fromConnector = $data->from_connector ?? '';
        $toConnector = $data->to_connector ?? '';

        if (empty($fromConnector) || empty($toConnector)) {
            Flight::json(['error' => 'Source and destination connectors required'], 400);
            return;
        }

        try {
            $result = \Phuppi\UploadedFile::findFiltered(null, null, '', 'date_newest', PHP_INT_MAX, 0);
            $files = $result['files'];
            $fileIds = array_map(fn($file) => $file->id, $files);
            $fileData = array_map(fn($file) => [
                'id' => $file->id,
                'display_filename' => $file->display_filename,
                'filesize' => $file->filesize
            ], $files);
            Flight::json(['file_ids' => $fileIds, 'files' => $fileData]);

        } catch (\Exception $e) {
            Flight::json(['error' => 'Failed to get files: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Tests the connection to a storage connector.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function testConnection($data): void
    {
        $type = $data->connector_type ?? '';
        if ($type !== 's3') {
            Flight::json(['error' => 'Test connection only supported for S3'], 400);
            return;
        }

        $config = [
            'bucket' => $data->s3_bucket ?? '',
            'region' => $data->s3_region ?? 'us-east-1',
            'key' => $data->s3_key ?? '',
            'secret' => $data->s3_secret ?? '',
            'endpoint' => $data->s3_endpoint ?? null,
            'path_prefix' => $data->path_prefix ?? '',
        ];

        try {

            $storage = new \Phuppi\Storage\S3Storage($config);
            $success = $storage->testConnection();

            if ($success) {
                Flight::json(['message' => 'Connection successful']);
            } else {
                Flight::json(['error' => 'Connection failed'], 400);
            }
        } catch (\Exception $e) {
            Flight::json(['error' => 'Connection failed: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Reloads storage connectors from the database.
     *
     * @return void
     */
    private function reloadConnectors(): void
    {
        $db = Flight::db();
        $connectors = [];

        $rows = $db->query("SELECT name, type, config FROM storage_connectors")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $connectors[$row['name']] = json_decode($row['config'], true);
            $connectors[$row['name']]['type'] = $row['type'];
        }

        Flight::set('storage_connectors', $connectors);
    }

    /**
     * Scans for orphaned database records.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function scanOrphanedRecords($data): void
    {
        $db = Flight::db();
        $orphaned = [];

        // First, get all existing files from storage
        $existingFiles = $this->getAllExistingFiles();

        // Then check each database record against the existing files
        $stmt = $db->query('SELECT id, user_id, voucher_id, filename, display_filename, filesize FROM uploaded_files');
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $file = new \Phuppi\UploadedFile($row);
            $path = $file->getUsername() . '/' . $file->filename;
            if (!isset($existingFiles[$path])) {
                $orphaned[] = [
                    'id' => $file->id,
                    'display_filename' => $file->display_filename,
                    'path' => $path,
                    'filesize' => $file->filesize
                ];
            }
        }

        Flight::json(['orphaned_records' => $orphaned]);
    }

    /**
     * Gets all existing files from storage.
     *
     * @return array Array of existing files.
     */
    private function getAllExistingFiles(): array
    {
        $storage = Flight::storage();
        $existingFiles = [];

        if ($storage instanceof \Phuppi\Storage\LocalStorage) {
            $basePath = $storage->getBasePath();
            $pathPrefix = $storage->getPathPrefix();
            $scanPath = $pathPrefix ? $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pathPrefix) : $basePath;

            if (is_dir($scanPath)) {
                
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scanPath, \RecursiveDirectoryIterator::SKIP_DOTS));
                
                foreach ($iterator as $file) {

                    if ($file->isFile()) {
                        $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                        
                        if ($pathPrefix && str_starts_with($relativePath, $pathPrefix . '/')) {
                            $relativePath = substr($relativePath, strlen($pathPrefix) + 1);
                        }

                        $existingFiles[$relativePath] = true;
                    }
                }
            }

        } elseif ($storage instanceof \Phuppi\Storage\S3Storage) {

            $bucket = $storage->getBucket();
            $pathPrefix = $storage->getPathPrefix();
            $s3Client = $storage->getS3Client();

            try {
                $params = [
                    'Bucket' => $bucket,
                ];
                if ($pathPrefix) {
                    $params['Prefix'] = $pathPrefix . '/';
                }

                do {
                    $result = $s3Client->listObjectsV2($params);
                    if (isset($result['Contents'])) {
                        foreach ($result['Contents'] as $object) {
                            $key = $object['Key'];
                            $relativePath = $pathPrefix ? substr($key, strlen($pathPrefix) + 1) : $key;
                            $existingFiles[$relativePath] = true;
                        }
                    }

                    $params['ContinuationToken'] = $result['NextContinuationToken'] ?? null;

                } while (isset($result['NextContinuationToken']));

            } catch (\Exception $e) {
                Flight::logger()->error('Failed to list S3 objects: ' . $e->getMessage());
                // Return empty array on error, so all records will be considered orphaned
                return [];
            }
        }

        return $existingFiles;
    }

    /**
     * Deletes orphaned database records.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function deleteOrphanedRecords($data): void
    {
        $ids = $data->ids ?? [];
        if (empty($ids) || !is_array($ids)) {
            Flight::json(['error' => 'Invalid or missing file IDs'], 400);
            return;
        }

        $db = Flight::db();
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $file = \Phuppi\UploadedFile::findById($id);
            if (!$file) {
                $errors[] = "File $id not found";
                continue;
            }
            if ($file->delete()) {
                $deleted++;
            } else {
                $errors[] = "Failed to delete record for file $id";
            }
        }

        Flight::json(['message' => "Deleted $deleted orphaned records", 'errors' => $errors]);
    }

    /**
     * Scans for orphaned files in storage.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function scanOrphanedFiles($data): void
    {
        $orphaned = [];

        // First, get all database files into a map for fast lookup
        $dbFiles = $this->getAllDatabaseFiles();

        // Then scan storage and check against DB files
        $storage = Flight::storage();

        if ($storage instanceof \Phuppi\Storage\LocalStorage) {

            $basePath = $storage->getBasePath();
            $pathPrefix = $storage->getPathPrefix();
            $scanPath = $pathPrefix ? $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pathPrefix) : $basePath;

            if (is_dir($scanPath)) {
                
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scanPath, \RecursiveDirectoryIterator::SKIP_DOTS));
               
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                        
                        if ($pathPrefix && str_starts_with($relativePath, $pathPrefix . '/')) {
                            $relativePath = substr($relativePath, strlen($pathPrefix) + 1);
                        }

                        // Check if it's under a username dir
                        $parts = explode('/', $relativePath);

                        if (count($parts) >= 2) {

                            $username = $parts[0];
                            $filename = implode('/', array_slice($parts, 1));
                            $key = $username . '/' . $filename;

                            if (!isset($dbFiles[$key])) {
                                $orphaned[] = [
                                    'path' => $relativePath,
                                    'size' => $file->getSize(),
                                    'username' => $username,
                                    'filename' => $filename
                                ];
                            }
                        }
                    }
                }
            }

        } elseif ($storage instanceof \Phuppi\Storage\S3Storage) {

            $bucket = $storage->getBucket();
            $pathPrefix = $storage->getPathPrefix();
            $s3Client = $storage->getS3Client();

            try {
                $params = [
                    'Bucket' => $bucket,
                ];
                if ($pathPrefix) {
                    $params['Prefix'] = $pathPrefix . '/';
                }

                do {

                    $result = $s3Client->listObjectsV2($params);

                    if (isset($result['Contents'])) {

                        foreach ($result['Contents'] as $object) {

                            $key = $object['Key'];
                            $relativePath = $pathPrefix ? substr($key, strlen($pathPrefix) + 1) : $key;

                            // Check if it's under a username dir
                            $parts = explode('/', $relativePath);

                            if (count($parts) >= 2) {
                                $username = $parts[0];
                                $filename = implode('/', array_slice($parts, 1));
                                $dbKey = $username . '/' . $filename;

                                if (!isset($dbFiles[$dbKey])) {
                                    $orphaned[] = [
                                        'path' => $relativePath,
                                        'size' => $object['Size'],
                                        'username' => $username,
                                        'filename' => $filename
                                    ];
                                }
                            }
                        }
                    }

                    $params['ContinuationToken'] = $result['NextContinuationToken'] ?? null;

                } while (isset($result['NextContinuationToken']));
                
            } catch (\Exception $e) {
                Flight::json(['error' => 'Failed to scan S3 bucket: ' . $e->getMessage()], 500);
                return;
            }
        } else {
            Flight::json(['error' => 'Unsupported storage type'], 400);
            return;
        }

        Flight::json(['orphaned_files' => $orphaned]);
    }

    /**
     * Gets all files from the database.
     *
     * @return array Array of database files.
     */
    private function getAllDatabaseFiles(): array
    {
        $db = Flight::db();
        $dbFiles = [];

        $stmt = $db->query('SELECT user_id, voucher_id, filename FROM uploaded_files');
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $userId = $row['user_id'];
            $voucherId = $row['voucher_id'];
            $filename = $row['filename'];

            $username = '';
            if ($userId) {
                $user = \Phuppi\User::findById($userId);
                if ($user) {
                    $username = $user->username;
                }
            } elseif ($voucherId) {
                $voucher = new \Phuppi\Voucher();
                if ($voucher->load($voucherId)) {
                    $username = 'voucher_' . $voucher->voucher_code;
                }
            }

            if ($username) {
                $key = $username . '/' . $filename;
                $dbFiles[$key] = true;
            }
        }

        return $dbFiles;
    }

    /**
     * Imports orphaned files into the database.
     *
     * @param mixed $data The request data.
     * @return void
     */
    private function importOrphanedFiles($data): void
    {
        $files = $data->files ?? [];
        if (empty($files) || !is_array($files)) {
            Flight::json(['error' => 'Invalid or missing files'], 400);
            return;
        }

        $imported = 0;
        $errors = [];

        foreach ($files as $fileData) {
            $path = $fileData['path'] ?? '';
            $username = $fileData['username'] ?? '';
            $filename = $fileData['filename'] ?? '';
            $size = $fileData['size'] ?? 0;

            if (empty($path) || empty($username) || empty($filename)) {
                $errors[] = 'Invalid file data';
                continue;
            }

            // Determine user_id or voucher_id
            $userId = null;
            $voucherId = null;
            if (str_starts_with($username, 'voucher_')) {
                $voucherCode = substr($username, 8);
                $voucher = \Phuppi\Voucher::findByCode($voucherCode);
                if ($voucher) {
                    $voucherId = $voucher->id;
                } else {
                    $errors[] = "Voucher not found for $username";
                    continue;
                }
            } else {
                $user = \Phuppi\User::findByUsername($username);
                if ($user) {
                    $userId = $user->id;
                } else {
                    $errors[] = "User not found for $username";
                    continue;
                }
            }

            // Get mimetype from extension or default
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $mimetype = $this->getMimeTypeFromExtension($extension);

            $file = new \Phuppi\UploadedFile();
            $file->user_id = $userId;
            $file->voucher_id = $voucherId;
            $file->filename = $filename;
            $file->display_filename = $filename; // Use filename as display
            $file->filesize = $size;
            $file->mimetype = $mimetype;
            $file->extension = $extension;

            if ($file->save()) {
                $imported++;
            } else {
                $errors[] = "Failed to save record for $path";
            }
        }

        Flight::json(['message' => "Imported $imported orphaned files", 'errors' => $errors]);
    }

    /**
     * Gets the MIME type from file extension.
     *
     * @param string $extension The file extension.
     * @return string The MIME type.
     */
    private function getMimeTypeFromExtension($extension): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'zip' => 'application/zip',
            // Add more as needed
        ];
        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }
}
