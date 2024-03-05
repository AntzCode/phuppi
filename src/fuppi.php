<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'Abstract' . DIRECTORY_SEPARATOR . 'Model.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'App.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'Config.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'Db.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'UploadedFile.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'User.php');


if (!defined('FUPPI')) {

    define('FUPPI', true);
    define('FUPPI_APP_PATH', __DIR__);
    define('FUPPI_PUBLIC_PATH', pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_DIRNAME));
    define('FUPPI_DATA_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data');

    if (file_exists(FUPPI_PUBLIC_PATH . DIRECTORY_SEPARATOR . 'install.php')) {
        exit('You must run the <a href="/install.php">install script</a> before you can use fuppi.');
    }

    require_once('functions.php');

    register_shutdown_function('fuppi_end');

    fuppi_start();

    $config = Fuppi\Config::getInstance();

    session_start();

    $app = Fuppi\App::getInstance();
    $pdo = $app->getDb()->getPdo();
    $user = $app->getUser();
} else {

    if (defined('FUPPI_CLI')) {

        define('FUPPI_APP_PATH', __DIR__);
        define('FUPPI_DATA_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data');

        require_once('functions.php');

        $config = Fuppi\Config::getInstance();
        session_start();
        $app = Fuppi\App::getInstance();
        $user = $app->getUser();
    }
}
