<?php
/**
 * v1.0.6 migration 1 : install phpLiteAdmin.
 */
$password = $password ?? $_POST['password'] ?? null;

foreach ($argv ?? [] as $arg) {
    if (substr($arg, 0, strlen('userId=')) === 'userId=') {
        $username = substr($arg, strlen('userId='));
    }
    if (substr($arg, 0, strlen('username=')) === 'username=') {
        $username = substr($arg, strlen('username='));
    }
    if (substr($arg, 0, strlen('password=')) === 'password=') {
        $password = substr($arg, strlen('password='));
    }
}

if (is_null($password)) {
    unset($password);
}

require(__DIR__ . DIRECTORY_SEPARATOR . 'phpLiteAdmin.php');
