<?php

use Fuppi\UploadedFile;
use Fuppi\User;
use Fuppi\UserPermission;

require('../fuppi.php');

if (!$user->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
    // not allowed to access the users list
    fuppi_add_error_message('Not permitted');
    redirect('/');
}

if (!empty($_POST)) {
    switch ($_POST['_action']) {
        case 'saveSettings':
            foreach ($config->settings as $defaultSetting) {
                $config->setSetting($defaultSetting['name'], $_POST[$defaultSetting['name']]);
            }
            fuppi_add_success_message('Settings saved!');
            break;

        case 'garbageCollection':
            fuppi_gc();
            fuppi_add_success_message('Clean-up complete!');
            break;

        case 'applyMigrations':
            $username = $_POST['username'];
            $password = $_POST['password'];

            if ($authenticatingUser = User::findByUsername($_POST['username'] ?? '')) {
                if (!empty("{$authenticatingUser->password}") && password_verify($_POST['password'], $authenticatingUser->password)) {
                    if (!$authenticatingUser->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
                        fuppi_add_error_message('User is not permitted to perform Migrations');
                        sleep(5);
                        // redirect();
                    } else {
                        // process the migrations
                        ?>
                            <div class="ui segment">
                                <h2>Processing Migrations</h2>
                                <pre class="border-solid-thin"><?php require_once(FUPPI_APP_PATH . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'migrate.php'); ?></pre>
                                <p><a class="ui left icon button primary large" href="<?= $_SERVER['REQUEST_URI'] ?>"><i class="check icon"></i> Continue</a></p>
                            </div>
                        <?php
                        exit;
                    }
                } else {
                    fuppi_add_error_message('Invalid password');
                    sleep(5);
                    // redirect();
                }
            } else {
                fuppi_add_error_message('Invalid Username');
                sleep(5);
            }
            break;

        case 'syncToAwsS3':

            $numFilesPut = 0;

            $sdk = new Aws\Sdk([
                'region' => $config->getSetting('aws_s3_region'),
                'credentials' =>  [
                    'key'    => $config->getSetting('aws_s3_access_key'),
                    'secret' => $config->getSetting('aws_s3_secret')
                ]
            ]);

            $s3Client = $sdk->createS3();

            foreach (UploadedFile::getAll() as $uploadedFile) {
                try {
                    $meta = $s3Client->headObject([
                        'Bucket' => $config->getSetting('aws_s3_bucket'),
                        'Key' => $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename
                    ]);
                } catch (\Exception $e) {
                    $meta = false;
                }
                if (!$meta && file_exists($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename)) {
                    $s3Client->putObject([
                        'Bucket' => $config->getSetting('aws_s3_bucket'),
                        'Key' => $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename,
                        'SourceFile' => $config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename
                    ]);
                    $numFilesPut++;
                }
            }
            fuppi_add_success_message($numFilesPut . ' files uploaded to S3 bucket');
            break;

        case 'syncFromAwsS3':

            $numFilesGot = 0;

            $sdk = new Aws\Sdk([
                'region' => $config->getSetting('aws_s3_region'),
                'credentials' =>  [
                    'key'    => $config->getSetting('aws_s3_access_key'),
                    'secret' => $config->getSetting('aws_s3_secret')
                ]
            ]);

            $s3Client = $sdk->createS3();

            $s3Client->registerStreamWrapper();

            foreach (UploadedFile::getAll() as $uploadedFile) {
                try {
                    $meta = $s3Client->headObject([
                        'Bucket' => $config->getSetting('aws_s3_bucket'),
                        'Key' => $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename
                    ]);
                } catch (\Exception $e) {
                    $meta = false;
                }

                if ($meta && !file_exists($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename)) {
                    if (!is_dir($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username)) {
                        mkdir($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username, 0777, true);
                    }

                    if ($s3Stream = fopen('s3://' . $config->getSetting('aws_s3_bucket') . '/' . $config->s3_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename, 'r')) {
                        $fileStream = fopen($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename, 'w');
                        while (!feof($s3Stream)) {
                            $chunk = fread($s3Stream, 1024);
                            fwrite($fileStream, $chunk);
                        }
                        fclose($s3Stream);
                        fclose($fileStream);
                        $numFilesGot++;
                    }
                }
            }
            fuppi_add_success_message($numFilesGot . ' files downloaded from S3 bucket');
            break;
    }
}

$allSettings = $config->getSetting();

?>

<div class="ui segment">
    <div class="ui large breadcrumb">
        <a class="section" href="/">Home</a>
        <i class="right chevron icon divider"></i>
        <a class="section">Administration</a>
        <i class="right chevron icon divider"></i>
        <div class="active section">Settings</div>
    </div>
</div>

<div class="ui segment">

    <h2 class="header"><i class="cog icon"></i> Settings</h2>

    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
        <input type="hidden" name="_action" value="saveSettings" />

        <?php foreach ($config->settings as $defaultSetting) { ?>

            <?php if ($defaultSetting['type'] === 'boolean') { ?>

                <div class="field <?= (!empty($errors[$defaultSetting['name']] ?? []) ? 'error' : '') ?>">
                    <label for="<?= $defaultSetting['name'] . 'Field' ?>"><?= $defaultSetting['name'] ?>: </label>
                    <select id="<?= $defaultSetting['name'] . 'Field' ?>" name="<?= $defaultSetting['name'] ?>">
                        <option value="0" <?= $config->getSetting($defaultSetting['name']) > 0 ? '' : 'selected="selected"' ?>>No</option>
                        <option value="1" <?= $config->getSetting($defaultSetting['name']) > 0 ? 'selected="selected"' : '' ?>>Yes</option>
                    </select>
                </div>

            <?php } ?>

            <?php if ($defaultSetting['type'] === 'string') { ?>

                <div class="field <?= (!empty($errors[$defaultSetting['name']] ?? []) ? 'error' : '') ?>">
                    <label for="<?= $defaultSetting['name'] . 'Field' ?>"><?= $defaultSetting['name'] ?>: </label>
                    <input id="<?= $defaultSetting['name'] . 'Field' ?>" type="text" name="<?= $defaultSetting['name'] ?>" value="<?= $_POST[$defaultSetting['name']] ?? $config->getSetting($defaultSetting['name']) ?>" />
                </div>

            <?php } ?>

            <?php if ($defaultSetting['type'] === 'password') { ?>

                <div class="field <?= (!empty($errors[$defaultSetting['name']] ?? []) ? 'error' : '') ?>">
                    <label for="<?= $defaultSetting['name'] . 'Field' ?>"><?= $defaultSetting['name'] ?>: </label>
                    <input id="<?= $defaultSetting['name'] . 'Field' ?>" type="password" name="<?= $defaultSetting['name'] ?>" value="<?= $_POST[$defaultSetting['name']] ?? $config->getSetting($defaultSetting['name']) ?>" />
                </div>

            <?php } ?>

        <?php } ?>

        <button class="ui right labeled icon green button" type="submit"><i class="check icon left"></i> Save Settings</button>

    </form>
</div>

<div class="ui segment">

    <h2 class="header"><i class="oil can icon"></i> Maintenance</h2>

    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">

        <div class="ui cards">

            <div class="card">
                <div class="content">
                    <h3 class="header">Sync down from the cloud</h3>
                    <class="description">
                        <div>
                            <p>Files that have been uploaded to AWS will be downloaded to the server (consumes server space).</p>
                            <p>Use this feature if you have been using AWS S3 for storage but you want to copy the files onto the server.</p>
                        </div>
                </div>
                <div class="extra content">
                    <button type="submit" name="_action" value="syncFromAwsS3" class="ui button green icon left labeled"><i class="down arrow icon"></i> Sync from AWS S3</button>
                </div>
            </div>

            <div class="card">
                <div class="content">
                    <h3 class="header">Sync up to the cloud</h3>
                    <div class="description">
                        <p>Files that have been saved onto the server will be uploaded to AWS S3 (may incur costs AWS service fees).</p>
                        <p>Use this feature if you have been using the server for uploading files but now you want to begin hosting the files on AWS S3.</p>
                    </div>
                </div>
                <div class="extra content">
                    <button type="submit" name="_action" value="syncToAwsS3" class="ui button green icon left labeled"><i class="up arrow icon"></i> Sync to AWS S3</button>
                </div>
            </div>

            <div class="card">
                <div class="content">
                    <h3 class="header">Garbage Collection</h3>
                    <div class="description">
                        <p>From time to time the database needs to be purged of old, stale data such as expired tokens.</p>
                        <p>Do this as often as you want to keep the database optimized.</p>
                        <p>Current database filesize: <?= human_readable_bytes(filesize($config->sqlite3_file_path)) ?></p>
                    </div>
                </div>
                <div class="extra content">
                    <button type="submit" name="_action" value="garbageCollection" class="ui button green icon left labeled"><i class="database icon"></i> Clean up Database</button>
                </div>
            </div>

            <div class="card">
                <div class="content">
                    <h3 class="header">Migrations</h3>
                    <div class="description">
                        <p>Apply the latest migrations</p>
                        <div class="field <?php echo(!empty($errors['username'] ?? []) ? 'error' : ''); ?>">
                            <label for="username">Username: </label>
                            <input id="username" type="text" name="username"
                                value="<?php echo $_POST['username'] ?? ''; ?>" />
                        </div>

                        <div class="field <?php echo(!empty($errors['password'] ?? []) ? 'error' : ''); ?>">
                            <label for="password">Password: </label>
                            <input id="password" type="password" name="password"
                                value="<?php echo $_POST['password'] ?? ''; ?>" />
                        </div>
                    </div>

                </div>
                <div class="extra content">
                    <button type="submit" name="_action" value="applyMigrations"
                        class="ui button green icon left labeled"><i class="cog icon"></i> Process Migrations</button>
                </div>
            </div>

        </div>

    </form>

</div>
