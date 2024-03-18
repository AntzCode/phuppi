<?php

use Fuppi\UploadedFile;
use Fuppi\UserPermission;
use Fuppi\VoucherPermission;

require('fuppi.php');

$errors = [];

if ($uploadedFile = UploadedFile::getOne((int) $_GET['id'] ?? 0)) {

    $isValidToken = false;

    if (isset($_GET['token'])) {
        $token = $_GET['token'];
        if ($uploadedFile->isValidToken($token)) {
            $isValidToken = true;
        } else {
            fuppi_add_error_message('Invalid token');
        }
    }

    $isMyFile = $uploadedFile->user_id === $user->user_id;

    if ($voucher = $app->getVoucher()) {
        if ($uploadedFile->voucher_id !== $voucher->voucher_id) {
            if (!$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_LIST_ALL)) {
                // not allowed to read a file that was not uploaded with the voucher they are using
                $isMyFile = false;
            }
        }
    }

    $canReadUsers = $user->hasPermission(UserPermission::USERS_READ);
    $canReadFiles = $user->hasPermission(UserPermission::UPLOADEDFILES_READ);

    if ($isValidToken || (($isMyFile || $canReadUsers) && $canReadFiles)) {
        header('Content-Type: ' . $uploadedFile->mimetype);
        header('Content-Disposition: attachment; filename="' . $uploadedFile->filename . '"');
        fuppi_stop();
        readfile($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename);
        exit;
    } else {
        fuppi_add_error_message('Not authorized');
    }
} else {
    fuppi_add_error_message('Cannot find that file');
}