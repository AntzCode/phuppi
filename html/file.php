<?php

use Aws\Lambda\LambdaClient;

use Fuppi\UploadedFile;
use Fuppi\UserPermission;
use Fuppi\VoucherPermission;

require('fuppi.php');

$errors = [];

try {
    $fileIds = json_decode($_GET['id']);
} catch(\Exception $e) {
}

if (is_array($fileIds) && count($fileIds) > 0) {
    // attempting to download multiple files

    $isValidToken = false;

    if (isset($_GET['token'])) {
        $token = $_GET['token'];
        if ($uploadedFile->isValidToken($token)) {
            $isValidToken = true;
        } else {
            fuppi_add_error_message('Invalid token');
            exit;
        }
    }

    $canReadUsers = $user->hasPermission(UserPermission::USERS_READ);
    $canReadFiles = $user->hasPermission(UserPermission::UPLOADEDFILES_READ);

    foreach ($fileIds as $fileId) {
        if ($uploadedFile = UploadedFile::getOne((int) $fileId ?? 0)) {
            $isMyFile = $uploadedFile->user_id === $user->user_id;

            if ($voucher = $app->getVoucher()) {
                if ($uploadedFile->voucher_id !== $voucher->voucher_id) {
                    if (!$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_LIST_ALL)) {
                        // not allowed to read a file that was not uploaded with the voucher they are using
                        fuppi_add_error_message('Not permitted to download that file! (' . $fileId . ')');
                        exit;
                    }
                }
            }

            if ($isValidToken || (($isMyFile || $canReadUsers) && $canReadFiles)) {
            }


            if (!$isMyFile) {
                fuppi_add_error_message('That is not your file: ' . $fileId);
                exit;
            }
        } else {
            fuppi_add_error_message('Invalid file id: ' . $fileId);
            exit;
        }
    }

    //only gets here if they have permission for all the requested file ids
    if ($config->getSetting('use_aws_s3')) {
        // use Lambda function to combine files on aws s3

        $archiveFilename = 'archive-' . count($fileIds) . '-files_' . date('Ymd_His') . '.zip';

        $sanitizedArchiveFilename = UploadedFile::generateUniqueFilename($archiveFilename);

        $archiveFilePath = $config->s3_uploaded_files_prefix . '/' . $user->username . '/' . $sanitizedArchiveFilename;

        $lambdaConfig = new stdClass();
        $lambdaConfig->filenames = [];
        $lambdaConfig->bucket = $config->getSetting('aws_s3_bucket');
        $lambdaConfig->zipFileName = $archiveFilePath;
        foreach ($fileIds as $fileId) {
            $uploadedFile = UploadedFile::getOne((int) $fileId);

            $lambdaFilename = $uploadedFile->display_filename === $uploadedFile->filename
                ? $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename
                : json_decode(json_encode([
                    'filename' => $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename,
                    'displayFilename' => $uploadedFile->display_filename
                ]))
            ;

            $lambdaConfig->filenames[] = $lambdaFilename;
        }

        $client = LambdaClient::factory(
            [
                'credentials' => [
                    'key' => $config->getSetting('aws_s3_access_key'),
                    'secret' => $config->getSetting('aws_s3_secret')
                ],
                'version' => 'latest',
                'region'  => $config->getSetting('aws_s3_region')
            ]
        );

        $result = $client->invoke([
            // The name your created Lamda function
            'FunctionName' => $config->getSetting('aws_lambda_multiple_zip_function_name'),
            'Payload' => json_encode($lambdaConfig)
        ]);

        $sdk = new Aws\Sdk([
            'region' => $config->getSetting('aws_s3_region'),
            'credentials' =>  [
                'key'    => $config->getSetting('aws_s3_access_key'),
                'secret' => $config->getSetting('aws_s3_secret')
            ]
        ]);

        $s3Client = $sdk->createS3();

        $meta = $s3Client->headObject([
            'Bucket' => $config->getSetting('aws_s3_bucket'),
            'Key' => $archiveFilePath
        ]);

        $statement = $pdo->prepare("INSERT INTO `fuppi_temporary_files` (`user_id`, `voucher_id`, `filename`, `filesize`, `mimetype`, `extension`, `expires_at`) VALUES (:user_id, :voucher_id, :filename, :filesize, :mimetype, :extension, :expires_at)");

        $statement->execute([
            'user_id' => $user->user_id,
            'voucher_id' => $app->getVoucher()->voucher_id ?? 0,
            'filename' => $sanitizedArchiveFilename,
            'filesize' => $meta['ContentLength'],
            'mimetype' => $meta['ContentType'],
            'extension' => 'zip',
            'expires_at' => date('Y-m-d H:i:s', time() + $config->getSetting('aws_token_lifetime_seconds'))
        ]);


        $expiresAt = time() + (int) $config->getSetting('aws_token_lifetime_seconds');
        $cmd = $s3Client->getCommand('GetObject', [
            'Bucket' => $config->getSetting('aws_s3_bucket'),
            'Key' => $archiveFilePath
        ]);
        $request = $s3Client->createPresignedRequest($cmd, time() + (int) $config->getSetting('aws_token_lifetime_seconds'));
        $presignedUrl = (string) $request->getUri();

        redirect($presignedUrl);
    } else {
        // zip the files locally and provide a download link
        $zip = new ZipArchive();

        $archiveFilename = 'archive-' . count($fileIds) . '-files_' . date('Ymd_His') . '.zip';

        $sanitizedArchiveFilename = UploadedFile::generateUniqueFilename($archiveFilename);

        $archiveFilePath = $config->uploaded_files_path . DIRECTORY_SEPARATOR . $user->username . DIRECTORY_SEPARATOR . $sanitizedArchiveFilename;

        if (file_exists($archiveFilePath)) {
            unlink($archiveFilePath);
        }
        if ($zip->open($archiveFilePath, ZIPARCHIVE::CREATE) !== true) {
            die("Could not open archive");
        }
        foreach ($fileIds as $fileId) {
            $uploadedFile = UploadedFile::getOne((int) $fileId);
            $zip->addFile($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename, $uploadedFile->display_filename);
        }
        // close and save archive

        $zip->close();

        $statement = $pdo->prepare("INSERT INTO `fuppi_temporary_files` (`user_id`, `voucher_id`, `filename`, `filesize`, `mimetype`, `extension`, `expires_at`) VALUES (:user_id, :voucher_id, :filename, :filesize, :mimetype, :extension, :expires_at)");

        $statement->execute([
            'user_id' => $user->user_id,
            'voucher_id' => $app->getVoucher()->voucher_id ?? 0,
            'filename' => $sanitizedArchiveFilename,
            'filesize' => filesize($archiveFilePath),
            'mimetype' => mime_content_type($archiveFilePath),
            'extension' => pathinfo($archiveFilePath, PATHINFO_EXTENSION),
            'expires_at' => date('Y-m-d H:i:s', time() + $config->getSetting('aws_token_lifetime_seconds'))
        ]);

        header('Content-Type: ' . mime_content_type($archiveFilePath));
        header('Content-Disposition: attachment; filename="' . $archiveFilename . '"');
        fuppi_stop();
        readfile($archiveFilePath);
        exit;
    }
} else if ($uploadedFile = UploadedFile::getOne((int) $_GET['id'] ?? 0)) {
    // has received a single file id in request
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

            $voucherId = ($app->getVoucher() ? $app->getVoucher()->voucher_id : null);

            if (!($presignedUrl = $uploadedFile->getAwsPresignedUrl('GetObject', $voucherId))) {
                $expiresAt = time() + (int) $config->getSetting('aws_token_lifetime_seconds');
                $cmd = $s3Client->getCommand('GetObject', [
                    'Bucket' => $config->getSetting('aws_s3_bucket'),
                    'Key' => $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename,
                    'ResponseContentDisposition' => 'attachment; filename ="' . $uploadedFile->display_filename . '"'
                ]);
                $request = $s3Client->createPresignedRequest($cmd, time() + (int) $config->getSetting('aws_token_lifetime_seconds'));
                $presignedUrl = (string) $request->getUri();
                $uploadedFile->setAwsPresignedUrl($presignedUrl, 'GetObject', $expiresAt, $voucherId);
            }
            redirect($presignedUrl);
        } else {
            header('Content-Type: ' . $uploadedFile->mimetype);
            header('Content-Disposition: attachment; filename="' . $uploadedFile->display_filename . '"');
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
