<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Pear' . DIRECTORY_SEPARATOR . 'Date' . DIRECTORY_SEPARATOR . 'HumanDiff.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Pear' . DIRECTORY_SEPARATOR . 'Date' . DIRECTORY_SEPARATOR . 'HumanDiff' . DIRECTORY_SEPARATOR . 'Locale.php');

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'Abstract' . DIRECTORY_SEPARATOR . 'Model.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'Abstract' . DIRECTORY_SEPARATOR . 'HasUser.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'App.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'Config.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'Db.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'UploadedFile.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'User.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'UserPermission.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'Voucher.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'Fuppi' . DIRECTORY_SEPARATOR . 'VoucherPermission.php');


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

    if (!is_null($user->session_expires_at)) {
        if (strtotime($user->session_expires_at) < time()) {
            // the session has expired
            $user->session_expires_at = null;
            $user->save();
            logout();
            redirect($_SERVER['REQUEST_URI']);
        }
    }
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
