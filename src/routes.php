<?php

/**
 * routes.php
 *
 * Routes configuration file for defining URL routes and handling installation checks in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

// Check if first migration has been run (users table exists)
$db = Flight::db();
$userCount = $db->query("SELECT count(*) as user_count FROM users")->fetchColumn();

if ($userCount < 1) {

    $usersTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();

    if (!$usersTableExists) {
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

    Flight::route('GET /login', [new \Phuppi\Controllers\UserController(), 'login']);
    Flight::route('POST /login', [new \Phuppi\Controllers\UserController(), 'login']);
    Flight::route('GET /logout', [new \Phuppi\Controllers\UserController(), 'logout']);
    Flight::route('GET /users', [new \Phuppi\Controllers\UserController(), 'listUsers']);
    Flight::route('POST /users', [new \Phuppi\Controllers\UserController(), 'createUser']);
    Flight::route('DELETE /users/@id', [new \Phuppi\Controllers\UserController(), 'deleteUser']);
    Flight::route('POST /users/@id/add-permission', [new \Phuppi\Controllers\UserController(), 'addPermission']);
    Flight::route('POST /users/@id/remove-permission', [new \Phuppi\Controllers\UserController(), 'removePermission']);

    // Voucher routes
    Flight::route('GET /vouchers', [new \Phuppi\Controllers\VoucherController(), 'listVouchers']);
    Flight::route('POST /vouchers', [new \Phuppi\Controllers\VoucherController(), 'createVoucher']);
    Flight::route('PUT /vouchers/@id', [new \Phuppi\Controllers\VoucherController(), 'updateVoucher']);
    Flight::route('DELETE /vouchers/@id', [new \Phuppi\Controllers\VoucherController(), 'deleteVoucher']);
    Flight::route('POST /vouchers/@id/add-permission', [new \Phuppi\Controllers\VoucherController(), 'addPermission']);
    Flight::route('POST /vouchers/@id/remove-permission', [new \Phuppi\Controllers\VoucherController(), 'removePermission']);

    // Admin settings
    Flight::route('GET /admin/settings', [new \Phuppi\Controllers\SettingsController(), 'index']);
    Flight::route('POST /admin/settings/storage', [new \Phuppi\Controllers\SettingsController(), 'updateStorage']);

    // File routes
    Flight::route('GET /files', [new \Phuppi\Controllers\FileController(), 'listFiles']);
    Flight::route('GET /files/@id', [new \Phuppi\Controllers\FileController(), 'getFile']);
    Flight::route('GET /files/thumbnail/@id', [new \Phuppi\Controllers\FileController(), 'getThumbnail']);
    Flight::route('GET /files/preview/@id', [new \Phuppi\Controllers\FileController(), 'getPreview']);

    Flight::route('GET /duplicates', [new \Phuppi\Controllers\FileController(), 'duplicates']);
    Flight::route('POST /duplicates', [new \Phuppi\Controllers\FileController(), 'deleteDuplicates']);
    Flight::route('POST /duplicates/verify', [new \Phuppi\Controllers\FileController(), 'verifyDuplicates']);

    Flight::route('POST /files', [new \Phuppi\Controllers\FileController(), 'uploadFile']);
    Flight::route('POST /files/presigned-url', [new \Phuppi\Controllers\FileController(), 'requestPresignedUrl']);
    Flight::route('POST /files/register', [new \Phuppi\Controllers\FileController(), 'registerUploadedFile']);
    Flight::route('PUT /files/@id', [new \Phuppi\Controllers\FileController(), 'updateFile']);
    Flight::route('DELETE /files/@id', [\Phuppi\Controllers\FileController::class, 'deleteFile']);
    Flight::route('DELETE /files', [new \Phuppi\Controllers\FileController(), 'deleteMultipleFiles']);
    Flight::route('POST /files/download', [new \Phuppi\Controllers\FileController(), 'downloadMultipleFiles']);
    Flight::route('POST /files/@id/share', [new \Phuppi\Controllers\FileController(), 'generateShareToken']);

    // Note routes
    Flight::route('GET /notes', [new \Phuppi\Controllers\NoteController(), 'index']);
    Flight::route('GET /notes/@id/shared', [new \Phuppi\Controllers\NoteController(), 'showSharedNote']);
    Flight::route('GET /api/notes', [new \Phuppi\Controllers\NoteController(), 'listNotes']);
    Flight::route('GET /api/notes/@id', [new \Phuppi\Controllers\NoteController(), 'getNote']);
    Flight::route('POST /api/notes', [new \Phuppi\Controllers\NoteController(), 'createNote']);
    Flight::route('PUT /api/notes/@id', [new \Phuppi\Controllers\NoteController(), 'updateNote']);
    Flight::route('DELETE /api/notes/@id', [new \Phuppi\Controllers\NoteController(), 'deleteNote']);
    Flight::route('POST /api/notes/@id/share', [new \Phuppi\Controllers\NoteController(), 'generateShareToken']);

    Flight::map('notFound', function () {
        Flight::logger()->info('Route not found: ' . Flight::request()->url);
    });
}
