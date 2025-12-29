<?php

// include Flight framework and session plugin
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'flight' . DIRECTORY_SEPARATOR . 'autoload.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'flight' . DIRECTORY_SEPARATOR . 'session' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Session.php');

// PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefixes = [
        'Phuppi\\' => __DIR__ . DIRECTORY_SEPARATOR . 'Phuppi' . DIRECTORY_SEPARATOR,
        'Latte\\' => __DIR__ . DIRECTORY_SEPARATOR . 'latte' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Latte' . DIRECTORY_SEPARATOR,
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

/**
 * create and configure view engine
 */
Flight::register('latte', 'Latte\Engine');

$latte = Flight::latte();
$latte->setTempDirectory(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache');
$latte->setLoader(new \Latte\Loaders\FileLoader(Flight::get('flight.views.path')));
$latte->addFunction('phuppi_version', [Phuppi\Helper::class, 'getPhuppiVersion']);
$latte->addFunction('get_user_messages', [Phuppi\Helper::class, 'getUserMessages']);

Flight::map('render', function(string $template, array $data, ?string $block=null) : void {
    $latte = Flight::latte();
    $latte->render($template, $data, $block);
});

/**
 * register Framework plugins
 */
Flight::register('session', 'flight\Session');
Flight::register('messages', '\Phuppi\Messages');


