<?php

/**
 * bootstrap.php
 *
 * Bootstrap file for initializing the Phuppi application, setting up autoloading, framework configuration, and services.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

// Register shutdown function to catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null) {
        $message = sprintf(
            "[%s] Fatal error: %s in %s on line %d",
            date('Y-m-d H:i:s'),
            $error['message'],
            $error['file'],
            $error['line']
        );
        error_log($message);
        Flight::logger()->error($message);
    }
});

// include Flight framework and session plugin
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'flight' . DIRECTORY_SEPARATOR . 'autoload.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'aws' . DIRECTORY_SEPARATOR . 'aws-autoloader.php');

// PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefixes = [
        'Phuppi\\' => __DIR__ . DIRECTORY_SEPARATOR . 'Phuppi' . DIRECTORY_SEPARATOR,
        'Latte\\' => __DIR__ . DIRECTORY_SEPARATOR . 'latte' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Latte' . DIRECTORY_SEPARATOR,
        'Valitron\\' => __DIR__ . DIRECTORY_SEPARATOR . 'valitron' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Valitron' . DIRECTORY_SEPARATOR,
        'Psr\\Log\\' => __DIR__ . DIRECTORY_SEPARATOR . 'psr' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR,
    ];

    foreach ($prefixes as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

// for some reason, these Latte exceptions aren't included by the autoloader
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'latte' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Latte' . DIRECTORY_SEPARATOR . 'exceptions.php');

/**
 * set Flight variables
 */
Flight::set('flight.views.path', __DIR__ . DIRECTORY_SEPARATOR . 'views');
Flight::set('flight.root.path', dirname(__DIR__));
Flight::set('flight.data.path', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data');
Flight::set('flight.public.path', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'html');
Flight::set('flight.cache.path', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache');

// Ensure cache directory exists
$cachePath = Flight::get('flight.cache.path');
if (!is_dir($cachePath)) {
    mkdir($cachePath, 0755, true);
}

/**
 * create and configure view engine
 */
Flight::register('latte', 'Latte\Engine');

$latte = Flight::latte();
// $latte->setTempDirectory(null); // Disable cache to avoid cache issues
$latte->setLoader(new \Latte\Loaders\FileLoader(Flight::get('flight.views.path')));
$latte->addFunction('phuppi_version', [Phuppi\Helper::class, 'getPhuppiVersion']);
$latte->addFunction('get_user_messages', [Phuppi\Helper::class, 'getUserMessages']);
$latte->addFunction('user_id', [Phuppi\Helper::class, 'getUserId']);
$latte->addFunction('voucher_id', [Phuppi\Helper::class, 'getVoucherId']);
$latte->addFunction('user_can', [Phuppi\Helper::class, 'can']);

Flight::map('render', function (string $template, array $data = [], ?string $block = null): void {
    $latte = Flight::latte();
    $latte->render($template, $data, $block);
});
Flight::register('logger', 'Phuppi\FileLogger');

/**
 * register Database plugin
 */

function register_flight_db() {

    $dbFilePath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'database.sqlite';
    
    Flight::register('db', 'PDO', array('sqlite:' .  $dbFilePath), function ($db) {
        // Set PDO attributes for better error handling
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // SQLite PRAGMAs for better concurrency with parallel preview processing
        // Write-Ahead Logging mode allows concurrent reads while writing
        $db->exec('PRAGMA journal_mode = WAL');
        // Wait up to 5 seconds when database is locked (instead of immediate failure)
        $db->exec('PRAGMA busy_timeout = 5000');
        // Normal synchronous level balances performance and durability
        $db->exec('PRAGMA synchronous = FULL');
        // Increase cache size for better performance (negative value = kilobytes)
        $db->exec('PRAGMA cache_size = -10000');
        // Checkpoint WAL log every 1000 pages
        $db->exec('PRAGMA wal_autocheckpoint = 1000');
    });

    $db = Flight::db();

    try {
        $result = $db->query("PRAGMA integrity_check;")->fetchColumn();
        $result = $db->query("PRAGMA quick_check;");
        $status = $result->fetchColumn();
    } catch (PDOException $e) {
        $status = 'error';
    }
    
    if ($status !== 'ok') {
        $lockFilePath = Flight::get('flight.data.path') . '/database-recovery.lock'; 
        if(file_exists($lockFilePath) && filemtime($lockFilePath) > (time() - 600)) {
            // database repair in progress
            Flight::halt(500, 'Database repair in progress');
        }
        touch($lockFilePath);

        $timestamp = time();

        $dbFilePath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'database.sqlite';
        $dbCopyPath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'database-error-' . $timestamp . '.sqlite';
        $dbRecoveryPath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'recovered-' . $timestamp . '.sql';
        $dbNewPath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'database-new-' . $timestamp . '.sqlite';

        // make a copy of the database
        copy($dbFilePath, $dbCopyPath);
        
        // recover the database
        $cmd = "sqlite3 " . escapeshellarg($dbCopyPath) . " \".recover\" > " . escapeshellarg($dbRecoveryPath);
        exec($cmd, $output, $returnCode);
        $cmd = "sqlite3 " . escapeshellarg($dbNewPath) . " < " . escapeshellarg($dbRecoveryPath);
        exec($cmd, $output, $returnCode);
        $cmd = "sqlite3 " . escapeshellarg($dbNewPath) . " \"PRAGMA integrity_check;\"";
        exec($cmd, $output, $returnCode);

        $integrity = trim(implode("\n", $output));
        
        if($integrity === 'ok') {
            // restore the database, cleanup and log
            rename($dbNewPath, $dbFilePath);
            unlink($dbRecoveryPath);
            unlink($lockFilePath);
            Flight::logger()->error('Database integrity recovered from critical error at timestamp ' . $timestamp . ': ' . $status);
            // reload the database
            register_flight_db();
        } else {
            // log the critical error (@TOTO: trigger notifications)
            Flight::logger()->critical('Database integrity failed recovery at timestamp ' . $timestamp . ': ' . $integrity . ' - ' . $status);
            // unlink($lockFilePath); // leave lock until it expires, to prevent excessive failures
            throw new RuntimeException("Database integrity failed");
        }

    }

}

register_flight_db();

// Load storage connectors configuration from database
$db = Flight::db();

/**
 * register Framework plugins
 */
Flight::register('session', '\Phuppi\DatabaseSession', [Flight::db(), ['table' => 'sessions']]);
Flight::register('messages', '\Phuppi\Messages');
Flight::register('user', 'Phuppi\User');
Flight::register('voucher', 'Phuppi\Voucher');
Flight::map('storage', function () {
    return \Phuppi\Storage\StorageFactory::create();
});

/**
 * Initialize migrations system
 */
Phuppi\Migration::init();


// Load connectors from storage_connectors table
$connectors = [];
$activeConnector = 'local-default';

$connectorRows = $db->query("SELECT name, type, config FROM storage_connectors")->fetchAll(PDO::FETCH_ASSOC);
foreach ($connectorRows as $row) {
    $connectors[$row['name']] = json_decode($row['config'], true);
    $connectors[$row['name']]['type'] = $row['type'];
}

// If no connectors, create default local
if (empty($connectors)) {
    $defaultConfig = [
        'type' => 'local',
        'path' => null, // Uses default data/uploads
        'name' => 'Local Storage (Default)',
    ];
    $db->prepare('INSERT INTO storage_connectors (name, type, config) VALUES (?, ?, ?)')->execute([
        'local-default',
        'local',
        json_encode($defaultConfig)
    ]);
    $connectors['local-default'] = $defaultConfig;
}

// Load active connector from settings (for now, later move to table)
$activeSetting = $db->query("SELECT value FROM settings WHERE name = 'active_storage_connector'")->fetchColumn();
if ($activeSetting) {
    $activeConnector = $activeSetting;
}

// Auto-create MinIO connector if it doesn't exist and we're in development/testing
if (!isset($connectors['minio-default']) && getenv('MINIO_ACCESS_KEY')) {
    $minioConfig = [
        'type' => 's3',
        'name' => 'MinIO Storage (Docker)',
        'bucket' => getenv('MINIO_BUCKETS') ?: 'phuppi-files',
        'region' => 'us-east-1',
        'key' => getenv('MINIO_ACCESS_KEY'),
        'secret' => getenv('MINIO_SECRET_KEY'),
        'endpoint' => 'http://minio:9000',
        'path_prefix' => getenv('MINIO_PATH_PREFIX') ?: 'data/uploadedFiles',
    ];
    $db->prepare('INSERT INTO storage_connectors (name, type, config) VALUES (?, ?, ?)')->execute([
        'minio-default',
        's3',
        json_encode($minioConfig)
    ]);
    $connectors['minio-default'] = $minioConfig;
}

Flight::set('storage_connectors', $connectors);
Flight::set('active_storage_connector', $activeConnector);

try {
    Flight::session();
} catch (Exception $e) {
    Flight::logger()->error('Failed to start session: ' . $e->getMessage());
}

// Check for session expiration (30 minutes)
// this will log the user out after 30 minutes of inactivity to prevent session hijacking if user has left the computer unattended
// @TODO: have a "keep me logged in" option or configuration in user settings
$lastActivity = Flight::session()->get('last_activity');
$sessionTimeout = 30 * 60; // 30 minutes
if ($lastActivity && (time() - $lastActivity) > $sessionTimeout) {
    Flight::logger()->warning('Session expired due to inactivity, destroying session. Last activity: ' . date('Y-m-d H:i:s', $lastActivity));
    Flight::session()->clear();
    Flight::redirect('/login');
}

/**
 * Initialize User or Voucher
 */
if (Flight::session()->get('voucher_id')) {
    Flight::voucher()->load(Flight::session()->get('voucher_id'));
    // Update session activity to prevent premature expiration
    Flight::session()->set('last_activity', time());
}

if (!Flight::voucher()->id && Flight::session()->get('id')) {
    Flight::user()->load(Flight::session()->get('id'));
    // Update session activity to prevent premature expiration
    Flight::session()->set('last_activity', time());
}

