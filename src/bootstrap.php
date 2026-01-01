<?php

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
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
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'flight' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'permissions' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Permission.php');
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
Flight::set('flight.data.path', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' );
Flight::set('flight.public.path', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'html' );
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
$latte->addFunction('user_can', [Phuppi\Helper::class, 'userCan']);

Flight::map('render', function(string $template, array $data=[], ?string $block=null) : void {
    $latte = Flight::latte();
    $latte->render($template, $data, $block);
});
Flight::register('logger', 'Phuppi\FileLogger');

/**
 * register Database plugin
 */
Flight::register('db', 'PDO', array('sqlite:' .  Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'database.sqlite'), function($db) {
    // Optional: Set PDO attributes for better error handling
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
});

// Load storage connectors configuration from database
$db = Flight::db();

// Load connectors from storage_connectors table
$connectors = [];
$activeConnector = 'local-default';

$connectorRows = $db->query("SELECT name, type, config FROM storage_connectors")->fetchAll(PDO::FETCH_ASSOC);
foreach ($connectorRows as $row) {
    $connectors[$row['name']] = json_decode($row['config'], true);
    $connectors[$row['name']]['type'] = $row['type'];
}

// Migrate existing connectors from settings to table
$settingsConnectors = $db->query("SELECT name, value FROM settings WHERE name LIKE 'storage_connector_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($settingsConnectors as $settingName => $value) {
    $connectorName = substr($settingName, strlen('storage_connector_'));
    if (!isset($connectors[$connectorName])) {
        $config = json_decode($value, true);
        $type = $config['type'];
        $db->prepare('INSERT INTO storage_connectors (name, type, config) VALUES (?, ?, ?)')->execute([
            $connectorName,
            $type,
            $value
        ]);
        $connectors[$connectorName] = $config;
        // Remove old setting
        $db->prepare('DELETE FROM settings WHERE name = ?')->execute([$settingName]);
    }
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

/**
 * register Framework plugins
 */
Flight::register('session', '\Phuppi\DatabaseSession', [Flight::db(), ['table' => 'sessions']]);
Flight::register('messages', '\Phuppi\Messages');
Flight::register('permissions', 'flight\Permission');
Flight::register('user', 'Phuppi\User');
Flight::map('storage', function() {
    return \Phuppi\Storage\StorageFactory::create();
});

/**
 * Initialize migrations system
 */
Phuppi\Migration::init();

Flight::session();

/**
 * Initialize User
 */
if(Flight::session()->get('id')) {
    Flight::user()->load(Flight::session()->get('id'));
    // Update session activity to prevent premature expiration
    Flight::session()->set('last_activity', time());
}

