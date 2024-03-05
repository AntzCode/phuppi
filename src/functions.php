<?php

function fuppi_start()
{
    ob_start();
}

function fuppi_end()
{
    if (defined('FUPPI_STOP')) {
        return;
    }
    $content = ob_get_contents();
    if (!ob_end_clean()) {
        throw new Exception('fuppie_end() called before fuppie_start() at line ' . __LINE__ . ' of ' . __FILE__);
    }
    $config = Fuppi\Config::getInstance();
    $app = Fuppi\App::getInstance();
    $pdo = $app->getDb()->getPdo();
    $user = $app->getUser();

    require(FUPPI_APP_PATH . DIRECTORY_SEPARATOR . 'templates/layout.php');
}

/**
 * fuppi_stop()
 * stops fuppi (enables an exit without rendering the output buffer & templates)
 *   - note: it also discards any output in the buffer, so grab that first if you still want to show it
 */
function fuppi_stop()
{
    while (ob_get_contents() > 0) {
        ob_end_clean();
    }
    define('FUPPI_STOP', 1);
}

function fuppi_component(string $componentName, array $variables = [])
{
    if (file_exists(FUPPI_APP_PATH . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . $componentName . '.php')) {
        $config = Fuppi\Config::getInstance();
        $app = Fuppi\App::getInstance();
        $pdo = $app->getDb()->getPdo();
        $user = $app->getUser();
        extract($variables);

        include(FUPPI_APP_PATH . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . $componentName . '.php');
    }
}

function human_readable_bytes($bytes, $decimals = 2, $system = 'binary')
{
    $mod = ($system === 'binary') ? 1024 : 1000;

    $units = [
        'binary' => ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'],
        'metric' => ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
    ];

    $factor = floor((strlen($bytes) - 1) / 3);

    return sprintf("%.{$decimals}f%s", $bytes / pow($mod, $factor), $units[$system][$factor]);
}

function fuppi_version(){
    return \Fuppi\App::getInstance()->getConfig()->fuppi_version;
}

function logout()
{
    $_SESSION = [];
    Fuppi\App::getInstance()->getUser()->setData(['user_id' => 0, 'username' => '', 'password' => '']);
}

function redirect(string $url = '')
{
    header('Location: ' . $url);
    exit('<script type="text/javascript">window.location="' . $url . '";</script><a href="' . $url . '">Continue</a>');
}
