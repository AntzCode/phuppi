<?php

use Fuppi\UploadedFile;
use Fuppi\User;
use Fuppi\UserPermission;

require('../src/fuppi.php');

if ($user->user_id <= 0) {
    redirect('/login.php?redirectAfterLogin=' . urlencode('/'));
}

$errors = [];

$profileUser = $user;

if (!empty($_GET['userId'])) {
    if ($user->hasPermission(UserPermission::USERS_READ)) {
        $profileUser = User::getOne($_GET['userId']) ?? $user;
    }
}

if (!empty($_POST)) {
    switch ($_POST['_method'] ?? 'post') {
        case 'delete':
            $fileId = $_POST['fileId'] ?? 0;
            UploadedFile::deleteOne($fileId);
            redirect($_SERVER['REQUEST_URI']);
            break;
    }
}

if (!empty($_FILES) && count($_FILES['files']['name']) > 0) {

    foreach ($_FILES['files']['name'] as $k => $filename) {

        try {
            switch ($_FILES['files']['error'][$k]) {
                case UPLOAD_ERR_OK:
                    break;
                case UPLOAD_ERR_NO_FILE:
                    throw new RuntimeException('No file sent.');
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new RuntimeException('"' . $_FILES['files']['name'][$k] . '": ' . 'Exceeded filesize limit.');
                default:
                    throw new RuntimeException('"' . $_FILES['files']['name'][$k] . '": ' . 'Unknown errors.');
            }
        } catch (RuntimeException $e) {
            $errors[] = [$e->getMessage()];
            continue;
        }

        $strippedFilename = preg_replace('/[^a-zA-Z0-9\.\-_(),]/', '', str_replace(' ', '_', $filename));

        $iterations = 0;
        do {
            $sanitizedFilename = ($iterations < 1 ? $strippedFilename : pathinfo($strippedFilename, PATHINFO_FILENAME) . '(' . ($iterations * 1) . ').' . pathinfo($strippedFilename, PATHINFO_EXTENSION));
            $iterations++;
            $statement = $pdo->prepare("SELECT COUNT(*) AS `tcount` FROM `fuppi_uploaded_files` WHERE `filename` = :filename");
        } while ($statement->execute(['filename' => $sanitizedFilename]) && $statement->fetch()['tcount'] > 0 && $iterations < 500);

        if (!file_exists($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $user->username)) {
            mkdir($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $user->username, 0777, true);
        }

        $storedFilepath = $config->uploadedFilesPath . DIRECTORY_SEPARATOR . $user->username . DIRECTORY_SEPARATOR . $sanitizedFilename;
        move_uploaded_file($_FILES['files']['tmp_name'][$k], $storedFilepath);

        $statement = $pdo->prepare("INSERT INTO `fuppi_uploaded_files` (`user_id`, `filename`, `filesize`, `mimetype`, `extension`) VALUES (:user_id, :filename, :filesize, :mimetype, :extension)");
        $statement->execute([
            'user_id' => $user->user_id,
            'filename' => $sanitizedFilename,
            'filesize' => filesize($storedFilepath),
            'mimetype' => mime_content_type($storedFilepath),
            'extension' => pathinfo($filename, PATHINFO_EXTENSION)
        ]);
    }

    if (!empty($errors)) {
        fuppi_add_error_message($errors);
    }

    redirect($_SERVER['REQUEST_URI']);
}

$uploadedFiles = $profileUser->getUploadedFiles();

?>

<h2>Welcome back, <?= $profileUser->username ?>!</h2>

<?php if ($user->hasPermission(UserPermission::UPLOADEDFILES_PUT)) { ?>

    <div class="ui segment <?= ($user->user_id !== $profileUser->user_id ? 'disabled' : '') ?> ">

        <div class="ui top attached label">
            <i class="upload icon"></i> <label for="files">Upload Files</label>
        </div>

        <form disabled class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post" enctype="multipart/form-data">
            <div class="field">
                <input <?= ($user->user_id !== $profileUser->user_id ? 'disabled="disabled"' : '') ?> id="files" type="file" name="files[]" placeholder="" multiple="multiple" />
            </div>
            <div class="ui container right aligned">
                <button <?= ($user->user_id !== $profileUser->user_id ? 'disabled="disabled"' : '') ?> class="ui green right labeled icon button" type="submit"><i class="upload icon right"></i> Upload</button>
            </div>

        </form>

    </div>

<?php } ?>

<?php if ($user->hasPermission(UserPermission::UPLOADEDFILES_LIST)) { ?>

    <div class="ui segment">

        <div class="ui top attached label">
            <i class="upload icon"></i> Your Uploaded Files</h2>
        </div>

        <?php if (empty($uploadedFiles)) { ?>

            <div class="ui content">
                <p>- Empty -</p>
            </div>

        <?php } else { ?>

            <div class="ui divided items">

                <?php foreach ($uploadedFiles as $uploadedFileIndex => $uploadedFile) { ?>

                    <div class="ui item " style="position: relative">

                        <button class="red ui top right attached round label raised clickable-confirm" style="z-index: 1;" data-confirm="Are you sure you want to delete this file?" data-action="(e) => document.getElementById('deleteUploadedFileForm<?= $uploadedFileIndex ?>').submit()">
                            <i class="trash icon"></i> Delete
                        </button>

                        <?php if ($user->hasPermission(UserPermission::UPLOADEDFILES_READ)) { ?>

                            <div class="ui modal modal<?= $uploadedFileIndex ?>">

                                <i class="close icon"></i>

                                <div class="header">
                                    Image Preview
                                </div>

                                <div class="image content">
                                    <img class="ui centered massive image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>&icon">
                                </div>
                                <div class="actions">
                                    <div class="ui positive right labeled icon button clickable" data-url="file.php?id=<?= $uploadedFile->uploaded_file_id ?>">
                                        Download
                                        <i class="download icon"></i>
                                    </div>
                                </div>

                            </div>

                        <?php } ?>

                        <?php if (
                            in_array($uploadedFile->mimetype, ['image/jpeg', 'image/png', 'image/giff'])
                            && $user->hasPermission(UserPermission::UPLOADEDFILES_READ)
                        ) { ?>

                            <div class="ui tiny rounded image clickable raised" onclick="$('.modal<?= $uploadedFileIndex ?>').modal('show')">
                                <img class="tiny rounded image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>&icon" />
                            </div>

                        <?php } else if (empty("{$uploadedFile->extension}")) { ?>

                            <div class="ui tiny image raised">
                                <img src="/assets/images/filetype-icons/unknown.png" />
                            </div>

                        <?php } else { ?>

                            <div class="ui tiny image raised">
                                <img src="/assets/images/filetype-icons/<?= $uploadedFile->extension ?>.png" />
                            </div>

                        <?php } ?>

                        <div class="content">

                            <span class="header"><?= $uploadedFile->filename ?></span>

                            <div class="meta">
                                <span><?= human_readable_bytes($uploadedFile->filesize) ?> <?= $uploadedFile->mimetype ?></span>
                                <span>Uploaded at <?= $uploadedFile->uploaded_at ?></span>
                            </div>

                            <?php if ($user->hasPermission(UserPermission::UPLOADEDFILES_READ)) { ?>
                                <div class="extra">
                                    <button class="ui labeled icon button clickable" data-url="file.php?id=<?= $uploadedFile->uploaded_file_id ?>"><i class="download icon"></i> Download</button>
                                </div>
                            <?php } ?>

                        </div>

                        <div class="right ui grid middle aligned">

                            <div class="one wide column">

                                <form id="deleteUploadedFileForm<?= $uploadedFileIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                                    <input name="_method" type="hidden" value="delete" />
                                    <input type="hidden" name="fileId" value="<?= $uploadedFile->uploaded_file_id ?>" />
                                    <!-- <button class="red circular ui icon button clickable-confirm" title="Delete" data-confirm="Are you sure you want to delete this file?" data-action="(e) => document.getElementById('deleteUploadedFileForm<?= $uploadedFileIndex ?>').submit()">
                                    <i class="icon trash"></i>
                                </button> -->
                                </form>

                            </div>

                        </div>

                    </div>

                <?php } ?>

            </div>

        <?php } ?>

    </div>

<?php } ?>