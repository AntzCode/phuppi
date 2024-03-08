<?php

use Fuppi\UploadedFile;
use Fuppi\UserPermission;

require('fuppi.php');

if ($user->user_id <= 0) {
    redirect('/login.php?redirectAfterLogin=' . urlencode('/file.php?id=' . $_GET['id']));
}

$errors = [];

if ($uploadedFile = UploadedFile::getOne((int) $_GET['id'] ?? 0)) {

    $isMyFile = $uploadedFile->user_id === $user->user_id;
    $canReadUsers = $user->hasPermission(UserPermission::USERS_READ);
    $canReadFiles = $user->hasPermission(UserPermission::UPLOADEDFILES_READ);

    if (($isMyFile || $canReadUsers) && $canReadFiles) {
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
