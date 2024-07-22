<?php

use Fuppi\UserPermission;

/**
 * v1.0.5 : Primary installation.
 */

$dbSchemaDirectoryName = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'databaseSchema';

echo 'Primary Installation (v1.0.5): create database tables..';

$tablenames = [
    'migrations.sql',
    // 'notes.sql',
    // 'note_tokens.sql',
    'settings.sql',
    'temporary_files.sql',
    'uploaded_files.sql',
    'uploaded_files_aws_auth.sql',
    'uploaded_file_tokens.sql',
    'user_permission.sql',
    'users.sql',
    'voucher_permission.sql',
    'vouchers.sql',
];

foreach ($tablenames as $tablename) {
    // create the database table
    $filename = $dbSchemaDirectoryName . DIRECTORY_SEPARATOR . $tablename;
    if (file_exists($filename)) {
        echo "create database table `{$tablename}` ({$filename})" . PHP_EOL;
        $query = file_get_contents($filename);
        $pdo->query($query);
    }
}

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

echo 'Create a User record for the Primary Administrator..' . PHP_EOL;

$statement = $pdo->prepare('INSERT INTO `fuppi_users` (`user_id`, `username`, `password`) VALUES (:user_id, :username, :password)');

$statement->execute([
    'user_id' => $userId,
    'username' => $username,
    'password' => password_hash($password, PASSWORD_BCRYPT)
]);

echo 'Create a record for the Primary Administrator permissions..' . PHP_EOL;

$statement = $pdo->prepare('INSERT INTO `fuppi_user_permissions` (`user_id`, `permission_name`, `permission_value`) VALUES (:user_id, :permission_name, :permission_value)');

$statement->execute([
    'user_id' => $userId,
    'permission_name' => UserPermission::IS_ADMINISTRATOR,
    'permission_value' => json_encode(true)
]);


echo 'Create a record for the default settings in the database..' . PHP_EOL;

$statement = $pdo->prepare('INSERT INTO `fuppi_settings` (`name`, `value`) VALUES (:name, :value)');

foreach ($config->settings as $defaultSetting) {
    $statement->execute([
        'name' => $defaultSetting['name'],
        'value' => $defaultSetting['value']
    ]);
}
