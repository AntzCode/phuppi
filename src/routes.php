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

use Phuppi\Controllers\InstallController;
use Phuppi\Controllers\NoteController;
use Phuppi\Controllers\UserController;
use Phuppi\Controllers\FileController;
use Phuppi\Controllers\VoucherController;
use Phuppi\Controllers\SettingsController;
use Phuppi\Controllers\UserSettingsController;
use Phuppi\Controllers\ShortLinkController;

use Phuppi\Helper;
use Phuppi\Note;
use Phuppi\NoteToken;
use Phuppi\Permissions\Middleware\IsAuthenticated;
use Phuppi\Permissions\Middleware\IsAuthenticatedUser;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;
use Phuppi\Permissions\Middleware\IsNotAuthenticatedVoucher;
use Phuppi\Permissions\Middleware\RequireLogin;
use Phuppi\UploadedFileToken;
use Phuppi\UploadedFile;
use Phuppi\BatchFileToken;

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
        Flight::route('POST /install', [InstallController::class, 'install']);
        Flight::route('*', [InstallController::class, 'index']);
    }
} else {
    // Normal routes
    Flight::route('GET /', [FileController::class, 'index'])->addMiddleware(RequireLogin::class);

    Flight::route('GET /login', [UserController::class, 'login']);
    Flight::route('POST /login', [UserController::class, 'login']);
    Flight::route('GET /logout', [UserController::class, 'logout']);

    Flight::router()->group('/users', function ($router) {

        // User can manage own profile
        Flight::route('GET /settings', [UserSettingsController::class, 'index'])->addMiddleware(IsNotAuthenticatedVoucher::class);
        Flight::route('POST /settings', [UserSettingsController::class, 'updatePassword'])->addMiddleware(IsNotAuthenticatedVoucher::class);
        Flight::route('POST /settings/shortlinks', [UserSettingsController::class, 'createShortLink'])->addMiddleware(IsNotAuthenticatedVoucher::class);
        Flight::route('DELETE /settings/shortlinks/@id', [UserSettingsController::class, 'deleteShortLink'])->addMiddleware(IsNotAuthenticatedVoucher::class);

        $router->get('/', [UserController::class, 'listUsers'])->addMiddleware(function () {
            return Helper::can(UserPermission::LIST);
        });
        $router->post('/', [UserController::class, 'createUser'])->addMiddleware(function () {
            return Helper::can(UserPermission::CREATE);
        });
        $router->delete('/@id', [UserController::class, 'deleteUser'])->addMiddleware(function () {
            return Helper::can(UserPermission::DELETE);
        });
        $router->post('/@id/add-permission', [UserController::class, 'addPermission'])->addMiddleware(function () {
            return Helper::can(UserPermission::PERMIT);
        });
        $router->post('/@id/remove-permission', [UserController::class, 'removePermission'])->addMiddleware(function () {
            return Helper::can(UserPermission::PERMIT);
        });
    }, [IsAuthenticatedUser::class]);

    // Voucher 
    Flight::router()->group('/vouchers', function ($router) {
        $router->get('/', [VoucherController::class, 'listVouchers'])->addMiddleware(function () {
            return Helper::can(VoucherPermission::LIST);
        });
        $router->post('/', [VoucherController::class, 'createVoucher'])->addMiddleware(function () {
            return Helper::can(VoucherPermission::CREATE);
        });
        $router->put('/@id', [VoucherController::class, 'updateVoucher'])->addMiddleware(function () {
            return Helper::can(VoucherPermission::UPDATE);
        });
        $router->delete('/@id', [VoucherController::class, 'deleteVoucher'])->addMiddleware(function () {
            return Helper::can(VoucherPermission::DELETE);
        });
        $router->post('/@id/add-permission', [VoucherController::class, 'addPermission'])->addMiddleware(function () {
            return Helper::can(VoucherPermission::UPDATE);
        });
        $router->post('/@id/remove-permission', [VoucherController::class, 'removePermission'])->addMiddleware(function () {
            return Helper::can(VoucherPermission::UPDATE);
        });
    }, [IsAuthenticatedUser::class]);

    // Admin settings
    Flight::router()->group('/admin', function ($router) {
        $router->get('/settings', [SettingsController::class, 'index']);
        $router->post('/settings/storage', [SettingsController::class, 'updateStorage']);
    }, [IsAuthenticatedUser::class]);

    // File routes
    Flight::router()->group('/files', function ($router) {
        $router->get('/', [FileController::class, 'listFiles'])->addMiddleware(function () {
            return Helper::can(FilePermission::LIST);
        });
        $router->get('/thumbnail/@id', [FileController::class, 'getThumbnail'])->addMiddleware(function () {
            return Helper::can(FilePermission::GET);
        });
        $router->get('/preview/@id', [FileController::class, 'getPreview'])->addMiddleware(function () {
            return Helper::can(FilePermission::GET);
        });
        $router->post('/', [FileController::class, 'uploadFile'])->addMiddleware(function () {
            return Helper::can(FilePermission::PUT);
        });
        $router->post('/presigned-url', [FileController::class, 'requestPresignedUrl'])->addMiddleware(function () {
            return Helper::can(FilePermission::PUT);
        });
        $router->post('/register', [FileController::class, 'registerUploadedFile'])->addMiddleware(function () {
            return Helper::can(FilePermission::CREATE);
        });
        $router->put('/@id', [FileController::class, 'updateFile'])->addMiddleware(function () {
            return Helper::can(FilePermission::PUT);
        });
        $router->delete('/@id', [\Phuppi\Controllers\FileController::class, 'deleteFile'])->addMiddleware(function () {
            return Helper::can(FilePermission::DELETE);
        });
        $router->delete('/', [FileController::class, 'deleteMultipleFiles'])->addMiddleware(function () {
            return Helper::can(FilePermission::DELETE);
        });
        $router->post('/download', [FileController::class, 'downloadMultipleFiles'])->addMiddleware(function () {
            return Helper::can(FilePermission::GET);
        });
        $router->post('/@id/share', [FileController::class, 'generateShareToken'])->addMiddleware(function () {
            return Helper::can(FilePermission::GET);
        });
        $router->post('/share-batch', [FileController::class, 'generateBatchShareToken'])->addMiddleware(function () {
            return Helper::can(FilePermission::GET);
        });
    }, [IsAuthenticated::class]);

    Flight::router()->get('/files/@id', [FileController::class, 'getFile'])->addMiddleware(function ($args) {
        // permitted user or token is allowed to access

        $fileId = (int) $args['id'];

        if(isset(Flight::request()->query['token'])) {
            $token = Flight::request()->query['token'];
            if (strlen($token) <= 255) {
                $fileToken = UploadedFileToken::findByToken($token);
                if ($fileToken && $fileToken->uploaded_file_id === $fileId) {
                    return true;
                }
                $batchToken = BatchFileToken::findByToken($token);
                if ($batchToken && in_array($fileId, $batchToken->file_ids)) {
                    return true;
                }
            }
        }

        return Helper::can(FilePermission::GET, UploadedFile::findById($fileId));
    });

    Flight::router()->get('/files/batch/@token', [FileController::class, 'showBatchShare'])->addMiddleware(function ($args) {
        $token = $args['token'];
        if (strlen($token) <= 255) {
            $batchToken = BatchFileToken::findByToken($token);
            if ($batchToken) {
                return true;
            }
        }
        Flight::halt(403, 'Invalid or expired token');
    });
    
    Flight::router()->group('/duplicates', function ($router) {
        $router->get('/', [FileController::class, 'duplicates'])->addMiddleware(function () {
            return Helper::can(FilePermission::GET)
                && Helper::can(FilePermission::LIST)
                && Helper::can(FilePermission::VIEW);
        });
        $router->post('/', [FileController::class, 'deleteDuplicates'])->addMiddleware(function () {
            return Helper::can(FilePermission::DELETE);
        });
        $router->post('/verify', [FileController::class, 'verifyDuplicates'])->addMiddleware(function () {
            return Helper::can(FilePermission::GET)
                && Helper::can(FilePermission::VIEW);
        });
    }, [IsAuthenticatedUser::class]);

    // Note routes
    Flight::router()->group('/notes', function ($router) {
        $router->get('/', [NoteController::class, 'index'])->addMiddleware(function () {
            return Helper::can(NotePermission::LIST);
        });
        $router->get('/list', [NoteController::class, 'listNotes'])->addMiddleware(function () {
            return Helper::can(NotePermission::LIST);
        });
        $router->get('/@id', [NoteController::class, 'getNote'])->addMiddleware(function () {
            return Helper::can(NotePermission::VIEW);
        });
        $router->post('/', [NoteController::class, 'createNote'])->addMiddleware(function () {
            return Helper::can(NotePermission::CREATE);
        });
        $router->put('/@id', [NoteController::class, 'updateNote'])->addMiddleware(function () {
            return Helper::can(NotePermission::UPDATE);
        });
        $router->delete('/@id', [NoteController::class, 'deleteNote'])->addMiddleware(function () {
            return Helper::can(NotePermission::DELETE);
        });
        $router->post('/@id/share', [NoteController::class, 'generateShareToken'])->addMiddleware(function () {
            return Helper::can(NotePermission::VIEW);
        });
    }, [IsAuthenticated::class]);

    Flight::router()->get('/notes/@id/shared', [NoteController::class, 'showSharedNote'])->addMiddleware(function ($args) {
        // permitted user or token is allowed to access

        $noteId = (int) $args['id'];

        if(isset(Flight::request()->query['token'])) {
            $token = Flight::request()->query['token'];
            if (strlen($token) <= 255) {
                $noteToken = NoteToken::findByToken($token);
                if ($noteToken && $noteToken->note_id === $noteId) {
                    return true;
                }
            }
        }

        return Helper::can(NotePermission::VIEW, Note::findById($noteId));
    });
    
    // Shortener routes
    Flight::route('GET /shortlinks', [ShortLinkController::class, 'index'])->addMiddleware(IsAuthenticated::class);
    Flight::route('POST /shorten', [FileController::class, 'shortenUrl'])->addMiddleware(IsAuthenticated::class);
    Flight::route('GET /s/@shortcode', [FileController::class, 'redirectShortLink']);
    
    Flight::map('notFound', function () {
        Flight::logger()->info('Route not found: ' . Flight::request()->url);
    });
}
