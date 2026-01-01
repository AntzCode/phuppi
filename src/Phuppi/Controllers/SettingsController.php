<?php

namespace Phuppi\Controllers;

use Flight;
use Phuppi\User;

class SettingsController
{
    public function index()
    {
        $sessionId = Flight::session()->get('id');
        if (!$sessionId) {
            Flight::redirect('/login');
        }

        $user = User::findById($sessionId);
        if (!$user || !$user->hasRole('admin')) {
            Flight::halt(403, 'Forbidden');
        }

        $connectors = Flight::get('storage_connectors') ?? [];
        $activeConnector = Flight::get('active_storage_connector') ?? 'local-default';
        Flight::render('settings.latte', [
            'connectors' => $connectors,
            'activeConnector' => $activeConnector
        ]);
    }

    public function updateStorage()
    {
        $sessionId = Flight::session()->get('id');
        if (!$sessionId) {
            Flight::redirect('/login');
        }

        $user = User::findById($sessionId);
        if (!$user || !$user->hasRole('admin')) {
            Flight::halt(403, 'Forbidden');
        }

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
            default:
                Flight::json(['error' => 'Invalid action'], 400);
                return;
        }

        Flight::json(['message' => 'Storage settings updated']);
    }

    private function setActiveConnector(string $connectorName)
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

    private function addConnector($data)
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

    private function updateConnector($data)
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

    private function deleteConnector(string $connectorName)
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

    private function migrateFiles($data)
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

    private function getMigrationFiles($data)
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
            Flight::json(['file_ids' => $fileIds]);
        } catch (\Exception $e) {
            Flight::json(['error' => 'Failed to get files: ' . $e->getMessage()], 500);
        }
    }

    private function testConnection($data)
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

    private function reloadConnectors()
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

}