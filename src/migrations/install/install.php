<?php

$username = empty($_POST['username'] ?? '') ? 'fuppi' : $_POST['username'];
$password = $_POST['password'] ?? 'password';

foreach ($argv ?? [] as $arg) {
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

$statement = $pdo->prepare('INSERT INTO `fuppi_users` (`username`, `password`) VALUES (:username, :password)');

$statement->execute([
    'username' => $username,
    'password' => password_hash($password, PASSWORD_BCRYPT)
]);
