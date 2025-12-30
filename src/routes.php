<?php

// Check if first migration has been run (users table exists)
$db = Flight::db();
$usersTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();

if (!$usersTableExists) {

    $fuppiUsersTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='fuppi_users'")->fetchColumn();

    if($fuppiUsersTableExists) {
        // perform migration to v2
        require_once __DIR__ . '/migrations/001_install_migration.php';
        Flight::redirect('/login');
        exit;
    } else {
        // Show installer
        Flight::route('POST /install', [\Phuppi\Controllers\InstallController::class, 'install']);
        Flight::route('*', [\Phuppi\Controllers\InstallController::class, 'index']);
    }
} else {
    // Normal routes
    Flight::route('GET /', function() {
        Flight::render('home.latte', ['name' => 'Phuppi!', 'sessionId' => Flight::session()->get('id')]);
    });

    Flight::route('GET /test1', function() {
        Flight::render('home.latte', ['name' => 'Test Phuppi!', 'sessionId' => Flight::session()->get('id')]);
    });

    Flight::route('GET /login', [new \Phuppi\Controllers\UserController(), 'login']);
    Flight::route('POST /login', [new \Phuppi\Controllers\UserController(), 'login']);
    Flight::route('GET /logout', [new \Phuppi\Controllers\UserController(), 'logout']);
}

