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

        if ($config->getSetting('use_aws_s3')) {

            $sdk = new Aws\Sdk([
                'region' => $config->getSetting('aws_s3_region'),
                'credentials' =>  [
                    'key'    => $config->getSetting('aws_s3_access_key'),
                    'secret' => $config->getSetting('aws_s3_secret')
                ]
            ]);

            $s3Client = $sdk->createS3();

            if (!isset($_GET['icon'])) {
                // we do not wrap a stream for icons, for performance reasons

                try {
                    $s3Client->registerStreamWrapper();

                    if ($stream = fopen('s3://' . $config->getSetting('aws_s3_bucket') . '/' . $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename, 'r')) {
                        header('Content-Type: ' . $uploadedFile->mimetype);
                        header('Content-Disposition: attachment; filename="' . $uploadedFile->filename . '"');
                        fuppi_stop();
                        while (!feof($stream)) {
                            echo fread($stream, 1024);
                            ob_flush();
                        }
                        fclose($stream);
                        exit;
                    }
                } catch (\Exception $e) {
                }
                // falls back to redirect if the stream failed
            }

            $voucherId = ($app->getVoucher() ? $app->getVoucher()->voucher_id : null);

            if (!($presignedUrl = $uploadedFile->getAwsPresignedUrl('GetObject', $voucherId))) {
                $expiresAt = time() + 20 * 60;
                $cmd = $s3Client->getCommand('GetObject', [
                    'Bucket' => $config->getSetting('aws_s3_bucket'),
                    'Key' => $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename
                ]);
                $request = $s3Client->createPresignedRequest($cmd, '+20 minutes');
                $presignedUrl = (string)$request->getUri();
                $uploadedFile->setAwsPresignedUrl($presignedUrl, 'GetObject', $expiresAt, $voucherId);
            }
            redirect($presignedUrl);
        } else {
            header('Content-Type: ' . $uploadedFile->mimetype);
            header('Content-Disposition: attachment; filename="' . $uploadedFile->filename . '"');
            fuppi_stop();
            readfile($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename);
            exit;
        }
    } else {
        fuppi_add_error_message('Not authorized');
    }
} else {
    fuppi_add_error_message('Cannot find that file');
}
