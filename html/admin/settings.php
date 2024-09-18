<?php

use Fuppi\FileSystem;
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

            if ($authenticatingUser = User::findByUsername($username ?? '')) {
                if (!empty("{$authenticatingUser->password}") && password_verify($password, $authenticatingUser->password)) {
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

        case 'accessDatabase':
            $username = $_POST['username'];
            $password = $_POST['password'];

            if ($authenticatingUser = User::findByUsername($username ?? '')) {
                if (!empty("{$authenticatingUser->password}") && password_verify($password, $authenticatingUser->password)) {
                    if (!$authenticatingUser->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
                        fuppi_add_error_message('User is not permitted to access the database');
                        sleep(5);
                        // redirect();
                    } else {
                        // redirect to the database
                        redirect('/' . $config->phpliteadmin_folder_name . '/phpliteadmin.php');
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

        case 'syncUpload':

            $numFilesPut = 0;

            foreach (UploadedFile::getAll() as $uploadedFile) {
                try {
                    $meta = $fileSystem->getObjectMetaData($config->remote_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename);
                } catch (\Exception $e) {
                    $meta = false;
                }
                if (!$meta && file_exists($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename)) {
                    $fileSystem->putObject($config->remote_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename, $config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename);
                    $numFilesPut++;
                }
            }
            fuppi_add_success_message($numFilesPut . ' files uploaded to Cloud');
            break;

        case 'syncDownload':

            $numFilesGot = 0;

            $fileSystem->getClient()->registerStreamWrapper();

            foreach (UploadedFile::getAll() as $uploadedFile) {
                try {
                    $meta = $fileSystem->getObjectMetaData($config->remote_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename);
                } catch (\Exception $e) {
                    $meta = false;
                }

                if ($meta && !file_exists($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename)) {
                    if (!is_dir($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username)) {
                        mkdir($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username, 0777, true);
                    }

                    if ($remoteStream = fopen($fileSystem->getRemoteUrl($config->remote_uploaded_files_prefix . '/' . $uploadedFile->getUser()->username . '/' . $uploadedFile->filename), 'r')) {
                        $fileStream = fopen($config->uploaded_files_path . DIRECTORY_SEPARATOR . $uploadedFile->getUser()->username . DIRECTORY_SEPARATOR . $uploadedFile->filename, 'w');
                        while (!feof($remoteStream)) {
                            $chunk = fread($remoteStream, 1024);
                            fwrite($fileStream, $chunk);
                        }
                        fclose($remoteStream);
                        fclose($fileStream);
                        $numFilesGot++;
                    }
                }
            }
            fuppi_add_success_message($numFilesGot . ' files downloaded from Cloud');
            break;
    }
}

$allSettings = $config->getSetting();

if (!class_exists('ZipArchive')) {
    fuppi_add_error_message('ZipArchive is not available on this server, so you will not be able to download multiple files unless you configure AWS S3.');
}

try {
    FileSystem::validateEndpoint();
} catch (\Exception $e) {
    fuppi_add_error_message($e->getMessage());
}

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

    <h2 class="ui header"><i class="cog icon"></i> Settings</h2>

    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
        <input type="hidden" name="_action" value="saveSettings" />

        <div class="ui three stackable cards">

            <?php foreach ($config->getDefaultSettings() as $defaultSetting) {
                $isDisabled = false; ?>

                <?php if (array_key_exists('show_if', $defaultSetting)) {
                    foreach ($defaultSetting['show_if'] as $showKey => $showValue) {
                        if (is_array($showValue)) {
                            if (!in_array($config->getSetting($showKey), $showValue)) {
                                $isDisabled = true;
                            }
                        } else {
                            if ($config->getSetting($showKey) !== $showValue) {
                                $isDisabled = true;
                            }
                        }
                    }
                } ?>

                <div class="ui card <?= ($isDisabled) ? 'disabled' : '' ?>"><div class="content">

                    <?php if ($defaultSetting['type'] === 'boolean') { ?>

                        <div class="field <?= (!empty($errors[$defaultSetting['name']] ?? []) ? 'error' : '') ?>">
                            <label for="<?= $defaultSetting['name'] . 'Field' ?>"><?= $defaultSetting['title'] ?>: </label>
                            <select id="<?= $defaultSetting['name'] . 'Field' ?>" name="<?= $defaultSetting['name'] ?>">
                                <option value="0" <?= $config->getSetting($defaultSetting['name']) > 0 ? '' : 'selected="selected"' ?>>No</option>
                                <option value="1" <?= $config->getSetting($defaultSetting['name']) > 0 ? 'selected="selected"' : '' ?>>Yes</option>
                            </select>
                        </div>

                    <?php } ?>

                    <?php if ($defaultSetting['type'] === 'string') { ?>

                        <div class="field <?= (!empty($errors[$defaultSetting['name']] ?? []) ? 'error' : '') ?>">
                            <label for="<?= $defaultSetting['name'] . 'Field' ?>"><?= $defaultSetting['title'] ?>: </label>
                            <input id="<?= $defaultSetting['name'] . 'Field' ?>" type="text" name="<?= $defaultSetting['name'] ?>" value="<?= $_POST[$defaultSetting['name']] ?? $config->getSetting($defaultSetting['name']) ?>" />
                        </div>

                    <?php } ?>

                    <?php if ($defaultSetting['type'] === 'password') { ?>

                        <div class="field <?= (!empty($errors[$defaultSetting['name']] ?? []) ? 'error' : '') ?>">
                            <label for="<?= $defaultSetting['name'] . 'Field' ?>"><?= $defaultSetting['title'] ?>: </label>
                            <input id="<?= $defaultSetting['name'] . 'Field' ?>" type="password" name="<?= $defaultSetting['name'] ?>" value="<?= $_POST[$defaultSetting['name']] ?? $config->getSetting($defaultSetting['name']) ?>" />
                        </div>

                    <?php } ?>

                    <?php if ($defaultSetting['type'] === 'option') { ?>

                        <div class="field <?= (!empty($errors[$defaultSetting['name']] ?? []) ? 'error' : '') ?>">
                            <label for="<?= $defaultSetting['name'] . 'Field' ?>"><?= $defaultSetting['title'] ?>: </label>
                            <select id="<?= $defaultSetting['name'] . 'Field' ?>" type="text" name="<?= $defaultSetting['name'] ?>">
                                <?php foreach ($defaultSetting['options'] as $value => $title) { ?>
                                    <option value="<?= $value ?>" <?= ((array_key_exists($defaultSetting['name'], $_POST) ? $_POST[$defaultSetting['name']] : $config->getSetting($defaultSetting['name'])) === $value) ? 'selected="selected"' : '' ?>><?= $title ?></option>
                                <?php } ?>
                            </select>
                        </div>

                    <?php } ?>

                </div></div>

            <?php } ?>

        </div>

        <div class="ui segment center aligned">
            <button class="ui right labeled icon primary button" type="submit"><i class="check icon left"></i> Save Settings</button>
        </div>
    </form>
</div>

<div class="ui segment">

    <h2 class="ui header"><i class="oil can icon"></i> Maintenance</h2>

        <div class="ui three stackable cards">

            <div class="card raised">
               <div class="content">
                    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
                        <h3 class="header center aligned">Sync down from the Cloud</h3>
                        <div class="description ui vertical segment">
                            <p>Files that have been uploaded to the Cloud will be downloaded to the server (consumes server space).</p>
                            <p>Use this feature if you have been using the Cloud for storage but you want to copy the files onto the server.</p>
                        </div>
                        <div class="ui vertical segment center aligned">
                            <button type="submit" name="_action" value="syncDownload" class="ui button green icon left labeled"><i class="down arrow icon"></i> Sync from Cloud</button>
                        </div>
                    </form>
                </div> 
            </div>

            <div class="card raised">
                <div class="content">
                    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
                        <h3 class="header center aligned">Sync up to the Cloud</h3>
                        <div class="description ui vertical segment">
                            <p>Files that have been saved onto the server will be uploaded to the Cloud (may incur costs: AWS/Digital Ocean service fees).</p>
                            <p>Use this feature if you have been using the server for uploading files but now you want to begin hosting the files on the Cloud.</p>
                        </div>
                        <div class="ui vertical segment center aligned">
                            <button type="submit" name="_action" value="syncUpload" class="ui button green icon left labeled"><i class="up arrow icon"></i> Sync to Cloud</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card raised">
                <div class="content">
                    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
                        <h3 class="header center aligned">Garbage Collection</h3>
                        <div class="description ui vertical segment">
                            <p>From time to time the database needs to be purged of old, stale data such as expired tokens.</p>
                            <p>Do this as often as you want to keep the database optimized.</p>
                            <p>Current database filesize: <?= human_readable_bytes(filesize($config->sqlite3_file_path)) ?></p>
                        </div>
                        <div class="ui vertical segment center aligned">
                            <button type="submit" name="_action" value="garbageCollection" class="ui button green icon left labeled"><i class="database icon"></i> Clean up Database</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card raised">
                <div class="content">
                    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
                        <h3 class="header center aligned">Software Updates</h3>
                        <div class="description ui vertical segment">
                            <p>Apply the latest software updates</p>
                            <div class="field <?php echo(!empty($errors['username'] ?? []) ? 'error' : ''); ?>">
                                <label for="migrationUsername">Username: </label>
                                <input id="migrationUsername" type="text" name="username"
                                    value="<?php echo $_POST['username'] ?? ''; ?>" />
                            </div>
                            <div class="field <?php echo(!empty($errors['password'] ?? []) ? 'error' : ''); ?>">
                                <label for="migrationPassword">Password: </label>
                                <input id="migrationPassword" type="password" name="password"
                                    value="<?php echo $_POST['password'] ?? ''; ?>" />
                            </div>
                        </div>
                        <div class="ui vertical segment center aligned">
                            <button type="submit" name="_action" value="applyMigrations"
                                class="ui button green icon left labeled"><i class="cog icon"></i> Process Migrations</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card raised">
                <div class="content">
                    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
                        <h3 class="header center aligned">Access Database</h3>
                        <div class="description ui vertical segment">
                            <p>Read/Write/Import/Export the SQLite3 database directly</p>
                            <div class="field <?php echo(!empty($errors['username'] ?? []) ? 'error' : ''); ?>">
                                <label for="dbUsername">Username: </label>
                                <input id="dbUsername" type="text" name="username" 
                                    value="<?php echo $_POST['username'] ?? ''; ?>" />
                            </div>
                            <div class="field <?php echo(!empty($errors['password'] ?? []) ? 'error' : ''); ?>">
                                <label for="dbPassword">Password: </label>
                                <input id="dbPassword" type="password" name="password" 
                                    value="<?php echo $_POST['password'] ?? ''; ?>" />
                            </div>
                        </div>
                        <div class="ui vertical segment center aligned">
                            <button type="submit" name="_action" value="accessDatabase"
                                class="ui button green icon left labeled"><i class="database icon"></i> Access Database</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

    </form>

</div>
