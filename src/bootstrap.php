<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'flight' . DIRECTORY_SEPARATOR . 'autoload.php');

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

Flight::set('flight.views.path', __DIR__ . DIRECTORY_SEPARATOR . 'views');
Flight::set('latte', new Latte\Engine());

Flight::map('render', function(string $template, array $data, ?string $block=null) : void{
    Flight::get('latte')->setTempDirectory(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache');
    $finalPath = Flight::get('flight.views.path') . DIRECTORY_SEPARATOR . $template;
    Flight::get('latte')->render($finalPath, $data, $block);
});

