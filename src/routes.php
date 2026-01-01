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
    Flight::route('GET /', [new \Phuppi\Controllers\FileController(), 'index']);

    Flight::route('GET /test1', function() {
        Flight::render('home.latte', ['name' => 'Test Phuppi!', 'sessionId' => Flight::session()->get('id')]);
    });

    Flight::route('GET /login', [new \Phuppi\Controllers\UserController(), 'login']);
    Flight::route('POST /login', [new \Phuppi\Controllers\UserController(), 'login']);
    Flight::route('GET /logout', [new \Phuppi\Controllers\UserController(), 'logout']);
    Flight::route('GET /users', [new \Phuppi\Controllers\UserController(), 'listUsers']);
    Flight::route('POST /users', [new \Phuppi\Controllers\UserController(), 'createUser']);
    Flight::route('DELETE /users/@id', [new \Phuppi\Controllers\UserController(), 'deleteUser']);
    Flight::route('POST /users/@id/add-permission', [new \Phuppi\Controllers\UserController(), 'addPermission']);
    Flight::route('POST /users/@id/remove-permission', [new \Phuppi\Controllers\UserController(), 'removePermission']);

    // File routes
    Flight::route('GET /files', [new \Phuppi\Controllers\FileController(), 'listFiles']);
    Flight::route('GET /files/@id', [new \Phuppi\Controllers\FileController(), 'getFile']);
    Flight::route('GET /files/thumbnail/@id', [new \Phuppi\Controllers\FileController(), 'getThumbnail']);
    Flight::route('GET /files/preview/@id', [new \Phuppi\Controllers\FileController(), 'getPreview']);


    Flight::route('POST /files', [new \Phuppi\Controllers\FileController(), 'uploadFile']);
    Flight::route('PUT /files/@id', [new \Phuppi\Controllers\FileController(), 'updateFile']);
    Flight::route('DELETE /files/@id', [\Phuppi\Controllers\FileController::class, 'deleteFile']);
    Flight::route('DELETE /files', [new \Phuppi\Controllers\FileController(), 'deleteMultipleFiles']);
    Flight::route('POST /files/download', [new \Phuppi\Controllers\FileController(), 'downloadMultipleFiles']);
    Flight::route('POST /files/@id/share', [new \Phuppi\Controllers\FileController(), 'generateShareToken']);

    Flight::map('notFound', function(){
        Flight::logger()->info('Route not found: ' . Flight::request()->url);
    });

}

