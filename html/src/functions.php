<?php

use \Fuppi\User;

function fuppi_start()
{
    ob_start();
}

function fuppi_end($template = 'layout')
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

    require(FUPPI_APP_PATH . DIRECTORY_SEPARATOR . 'templates/' . $template . '.php');
}

/**
 * fuppi_stop()
 * stops fuppi (enables an exit without rendering the output buffer & templates)
 *   - note: it also discards any output in the buffer, so grab that first if you still want to show it.
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

function fuppi_add_error_message($message)
{
    if (is_array($message)) {
        foreach ($message as $_message) {
            fuppi_add_error_message($_message);
        }
    } else {
        $_SESSION['fuppi_error_messages'] = array_merge($_SESSION['fuppi_error_messages'] ?? [], [$message]);
    }
}

function fuppi_add_success_message($message)
{
    if (is_array($message)) {
        foreach ($message as $_message) {
            fuppi_add_success_message($_message);
        }
    } else {
        $_SESSION['fuppi_success_messages'] = array_merge($_SESSION['fuppi_success_messages'] ?? [], [$message]);
    }
}

function fuppi_get_error_messages($preserve = false)
{
    if (!empty($_SESSION['fuppi_error_messages'] ?? [])) {
        $messages = $_SESSION['fuppi_error_messages'] ?? [];
        if (!$preserve) {
            $_SESSION['fuppi_error_messages'] = [];
        }
        if (!empty($messages)) {
            return $messages;
        }
    }
}

function fuppi_get_success_messages($preserve = false)
{
    if (!empty($_SESSION['fuppi_success_messages'] ?? [])) {
        $messages = $_SESSION['fuppi_success_messages'] ?? [];
        if (!$preserve) {
            $_SESSION['fuppi_success_messages'] = [];
        }
        if (!empty($messages)) {
            return $messages;
        }
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

function human_readable_time_remaining(int $unixTimestamp)
{
    $dh = new Date_HumanDiff();
    return $dh->get($unixTimestamp);
}


function unlink_recursive($pathname)
{
    if (is_dir($pathname)) {
        $filenames = scandir($pathname);
        foreach ($filenames as $filename) {
            if (!in_array($filename, ['.', '..'])) {
                if (is_dir($pathname . DIRECTORY_SEPARATOR . $filename) && !is_link($pathname . DIRECTORY_SEPARATOR . $filename)) {
                    unlink_recursive($pathname . DIRECTORY_SEPARATOR . $filename);
                } else {
                    unlink($pathname . DIRECTORY_SEPARATOR . $filename);
                }
            }
        }
        rmdir($pathname);
    } else {
        unlink($pathname);
    }
}

function fuppi_version()
{
    return \Fuppi\App::getInstance()->getConfig()->fuppi_version;
}

/**
 * Returns an array of available migrations. Migration folder names must follow the pattern YYYY-MM-DD_{$priorityAscending}.
 * @param array $existingMigrations : array of migrations to exclude (eg: any already executed)
 */
function list_migrations()
{
    $migrations = [];

    $installationDirectoryPath = FUPPI_APP_PATH . DIRECTORY_SEPARATOR . 'migrations';

    foreach (scandir($installationDirectoryPath, SCANDIR_SORT_ASCENDING) as $filename) {
        if (!preg_match('/^([0-9]{4,4}\-[0-9]{2,2}\-[0-9]{2,2})_?([0-9])*$/', $filename)) {
            // does not match the expected pattern: YYYY-MM-DD_{$priorityAscending}
            continue;
        }
        if ($migrationDate = strtotime(substr($filename, 0, 10))) {
            $migrations[] = $filename;
        }
    }

    return $migrations;
}

function logout()
{
    $config = Fuppi\App::getInstance()->getConfig();
    if (!empty($_COOKIE[$config->session_persist_cookie_name])) {
        Fuppi\App::getInstance()->getUser()->destroyPersistentCookie($_COOKIE[$config->session_persist_cookie_name]);
        setcookie($config->session_persist_cookie_name, session_id(), -1, $config->session_persist_cookie_path, $config->session_persist_cookie_domain);
        unset($_COOKIE[$config->session_persist_cookie_name]);
    }
    Fuppi\App::getInstance()->getUser()->setData(['user_id' => 0, 'username' => '', 'password' => '']);
    Fuppi\App::getInstance()->setVoucher(null);
    $_SESSION = [];
}

function redirect(string $url = '')
{
    header('Location: ' . $url);
    exit('<script type="text/javascript">window.location="' . $url . '";</script><a href="' . $url . '">Continue</a>');
}

function base_url()
{
    $protocol = 'http://';
    if (
        isset($_SERVER['HTTPS']) &&
        ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
        isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
        $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
    ) {
        $protocol = 'https://';
    }
    return $protocol . $_SERVER['SERVER_NAME'];
}

function fuppi_gc()
{
    $config=\Fuppi\App::getInstance()->getConfig();
    $db = \Fuppi\App::getInstance()->getDb();
    $fileSystem = \Fuppi\App::getInstance()->getFileSystem();

    // delete expired user sessions
    $statement = $db->getPdo()->query('DELETE FROM `fuppi_user_sessions` WHERE `session_expires_at` < :expires_at_floor');
    $results = $statement->execute(['expires_at_floor' => date('Y-m-d H:i:s')]);

    if (!$fileSystem->isRemote()) {
        // purge all aws presigned urls
        $statement = $db->getPdo()->query('DELETE  FROM `fuppi_uploaded_files_remote_auth` WHERE 1');
        $statement->execute();
    }

    // purge expired file tokens
    $statement = $db->getPdo()->query('DELETE  FROM `fuppi_uploaded_file_tokens` WHERE `expires_at` < :expires_at_floor');
    $statement->execute(['expires_at_floor' => date('Y-m-d H:i:s')]);

    // purge expired aws presigned urls
    $statement = $db->getPdo()->query('DELETE  FROM `fuppi_uploaded_files_remote_auth` WHERE `expires_at` < :expires_at_floor');
    $statement->execute(['expires_at_floor' => date('Y-m-d H:i:s')]);

    // delete expired temporary files
    $statement = $db->getPdo()->query('SELECT * FROM `fuppi_temporary_files` WHERE `expires_at` < :expires_at_floor');
    $results = $statement->execute(['expires_at_floor' => date('Y-m-d H:i:s')]);

    if ($results) {
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $data) {
            try {
                $fileSystem->deleteObject($config->remote_uploaded_files_prefix . '/' . User::getOne($data['user_id'])->username . '/' . $data['filename']);
            } catch (\Exception $e) {
            }
            try {
                $localFilepath = $config->uploaded_files_path . DIRECTORY_SEPARATOR . User::getOne($data['user_id'])->username . DIRECTORY_SEPARATOR . $data['filename'];

                if (file_exists($localFilepath)) {
                    unlink($localFilepath);
                }
            } catch (\Exception $e) {
            }

            $statement2 = $db->getPdo()->query('DELETE  FROM `fuppi_temporary_files` WHERE `temporary_file_id` = :file_id');
            $statement2->execute(['file_id' => $data['temporary_file_id']]);
        }
    }

    // vacuum the database file
    $statement = $db->getPdo()->query('VACUUM');
    $statement->execute();
}
