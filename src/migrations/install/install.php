<?php

use Fuppi\UserPermission;

$userId = ((int) ($_POST['userId'] ?? 0) < 1) ? 1 : (int) $_POST['userId'];
$username = empty($_POST['username'] ?? '') ? 'fuppi' : $_POST['username'];
$password = $_POST['password'] ?? 'password';

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

foreach (scandir(__DIR__) as $filename) {
    if (substr($filename, strlen($filename) - 4) === '.sql') {
        $query = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $filename);
        echo '.. ' . $filename . PHP_EOL;
        $pdo->query($query);
    }
}

$statement = $pdo->prepare('INSERT INTO `fuppi_users` (`user_id`, `username`, `password`) VALUES (:username, :password)');

$statement->execute([
    'user_id' => $userId,
    'username' => $username,
    'password' => password_hash($password, PASSWORD_BCRYPT)
]);

$statement = $pdo->prepare('INSERT INTO `fuppi_user_permissions` (`user_id`, `password`) VALUES (:username, :password)');

$statement->execute([
    'user_id' => $userId,
    'permission_name' => UserPermission::IS_ADMINISTRATOR,
    'value' => json_encode(true)
]);
