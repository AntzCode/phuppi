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
