<?php

use Fuppi\ApiResponse;
use Fuppi\UploadedFile;
use Fuppi\User;
use Fuppi\UserPermission;
use Fuppi\Voucher;
use Fuppi\VoucherPermission;

require('fuppi.php');

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
            if ($user->hasPermission(UserPermission::UPLOADEDFILES_DELETE)) {
                $fileId = $_POST['fileId'] ?? 0;
                UploadedFile::deleteOne($fileId);
            }
            redirect($_SERVER['REQUEST_URI']);
            break;
        case 'post':
            switch ($_POST['_action'] ?? '') {
                case 'createSharableLink':
                    $apiResponse = new ApiResponse();
                    $validFor = (int) $_POST['validFor'];
                    if (!array_key_exists($validFor, $config->token_valid_for_options)) {
                        $apiResponse->throwException('Valid for "' . $validFor . '" is not a valid option');
                    }
                    $uploadedFileId = (int) $_POST['fileId'];
                    if ($uploadedFile = UploadedFile::getOne($uploadedFileId)) {
                        if (!_can_read_file($uploadedFile)) {
                            $apiResponse->throwException('Not permitted');
                        } else {
                            $token = $uploadedFile->createToken($validFor);
                            $apiResponse->data = [
                                'token' => $token,
                                'url' => base_url() . '/file.php?id=' . $uploadedFile->uploaded_file_id . '&token=' . $token,
                                'expiresAt' => $uploadedFile->getTokenExpiresAt($token)
                            ];
                            $apiResponse->sendResponse();
                        }
                    } else {
                        $apiResponse->throwException('Invalid file id "' . $uploadedFileId . '"');
                    }
                    break;
            }
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

        $statement = $pdo->prepare("INSERT INTO `fuppi_uploaded_files` (`user_id`, `voucher_id`, `filename`, `filesize`, `mimetype`, `extension`) VALUES (:user_id, :voucher_id, :filename, :filesize, :mimetype, :extension)");

        $statement->execute([
            'user_id' => $user->user_id,
            'voucher_id' => $app->getVoucher()->voucher_id ?? 0,
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

if ($voucher = $app->getVoucher()) {
    if (!$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_LIST_ALL)) {
        foreach ($uploadedFiles as $uploadedFileIndex => $uploadedFile) {
            if ($uploadedFile->voucher_id !== $voucher->voucher_id) {
                unset($uploadedFiles[$uploadedFileIndex]);
            }
        }
        $uploadedFiles = array_values($uploadedFiles);
    }
}

?>

<h2>Welcome back, <?= $profileUser->username ?>!</h2>

<?php if ($user->hasPermission(UserPermission::UPLOADEDFILES_PUT)) { ?>

    <div class="ui segment <?= ($user->user_id !== $profileUser->user_id ? 'disabled' : '') ?> ">

        <div class="ui top attached label">
            <i class="upload icon"></i> <label for="files">Upload Files (max <?= $config->post_max_size ?>)</label>
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

                        <?php if ($user->hasPermission(UserPermission::UPLOADEDFILES_DELETE)) { ?>
                            <button class="red ui top right attached round label raised clickable-confirm" style="z-index: 1;" data-confirm="Are you sure you want to delete this file?" data-action="(e) => document.getElementById('deleteUploadedFileForm<?= $uploadedFileIndex ?>').submit()">
                                <i class="trash icon"></i> Delete
                            </button>
                        <?php } ?>

                        <?php if (_can_read_file($uploadedFile)) {  ?>

                            <div class="ui modal preview<?= $uploadedFileIndex ?>">

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
                                    <div class="ui positive right labeled icon button clickable" onclick="$('.share<?= $uploadedFileIndex ?>').modal('show')">
                                        Share
                                        <i class="share icon"></i>
                                    </div>
                                </div>

                            </div>

                            <div class="ui modal share<?= $uploadedFileIndex ?>">

                                <i class="close icon"></i>

                                <div class="header">
                                    Share File
                                </div>

                                <div class="content ui items">

                                    <div class="item">

                                        <div class="image">
                                            <?php if (
                                                in_array($uploadedFile->mimetype, ['image/jpeg', 'image/png', 'image/giff'])
                                                && _can_read_file($uploadedFile)
                                            ) { ?>
                                                <img class="tiny rounded image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>&icon" />
                                            <?php } else if (empty("{$uploadedFile->extension}")) { ?>
                                                <img src="/assets/images/filetype-icons/unknown.png" />
                                            <?php } else { ?>
                                                <img src="/assets/images/filetype-icons/<?= $uploadedFile->extension ?>.png" />
                                            <?php } ?>
                                        </div>

                                        <div class="content">

                                            <div class="header">
                                                <?= $uploadedFile->filename ?>
                                            </div>

                                            <div class="meta">
                                                <?= _get_meta_content($uploadedFile) ?>
                                            </div>

                                            <div class="description">

                                                <select name="valid_for" id="validForDropdown<?= $uploadedFileIndex ?>" style="display: none">
                                                    <?php foreach ($config->token_valid_for_options as $value => $title) {
                                                        echo '<option value="' . $value . '"' . (intval($_POST['valid_for'] ?? 0) === $value ? 'selected="selected"' : '') . '>' . $title . '</option>';
                                                    } ?>
                                                </select>

                                                <div class="ui labeled ticked slider attached" id="sharableLinkSlider<?= $uploadedFileIndex ?>"></div>

                                                <script>
                                                    $('#sharableLinkSlider<?= $uploadedFileIndex ?>')
                                                        .slider({
                                                            min: 0,
                                                            max: <?= count($config->token_valid_for_options) - 1 ?>,
                                                            start: <?= (array_search($_POST['valid_for'] ?? array_keys($config->token_valid_for_options)[0], array_keys($config->token_valid_for_options))) ?>,
                                                            autoAdjustLabels: true,
                                                            fireOnInit: true,
                                                            onChange: (v) => {
                                                                document.getElementById('validForDropdown<?= $uploadedFileIndex ?>').selectedIndex = v;
                                                                let formData = new FormData();
                                                                formData.append('_action', 'createSharableLink');
                                                                formData.append('fileId', <?= $uploadedFile->uploaded_file_id ?>);
                                                                formData.append('validFor', document.getElementById('validForDropdown<?= $uploadedFileIndex ?>').options[document.getElementById('validForDropdown<?= $uploadedFileIndex ?>').selectedIndex].value);
                                                                axios.post('<?= $_SERVER['REQUEST_URI'] ?>', formData).then((response) => {
                                                                    $('#sharableLink<?= $uploadedFileIndex ?>').text(response.data.url);
                                                                    $('#sharableLinkCopyButton<?= $uploadedFileIndex ?>').data('content', response.data.url);
                                                                    $('#expiresAt<?= $uploadedFileIndex ?>').text(response.data.expiresAt);
                                                                }).catch((error) => {
                                                                    if (error.response && error.response.data && error.response.data.message) {
                                                                        alert(error.response.data.message);
                                                                    } else {
                                                                        console.log(error);
                                                                    }
                                                                });
                                                            },
                                                            interpretLabel: function(value) {
                                                                let _labels = JSON.parse('<?= json_encode(array_values($config->token_valid_for_options)) ?>');
                                                                return _labels[value];
                                                            }
                                                        });
                                                </script>

                                            </div>

                                            <div class="extra">
                                                <div>
                                                    <p class="ui label"><span id="sharableLink<?= $uploadedFileIndex ?>"></span> <i id="sharableLinkCopyButton<?= $uploadedFileIndex ?>" class="clickable copy icon copy-to-clipboard" data-content="" title="Copy to clipboard"></i></p>
                                                </div>
                                                <div>
                                                    <p class="">Expires at: <span class="ui label" id="expiresAt<?= $uploadedFileIndex ?>"></span>
                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php } ?>

                        <?php if (
                            in_array($uploadedFile->mimetype, ['image/jpeg', 'image/png', 'image/giff'])
                            && _can_read_file($uploadedFile)
                        ) { ?>

                            <div class="ui tiny rounded image clickable raised" onclick="$('.preview<?= $uploadedFileIndex ?>').modal('show')">
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
                                <?= _get_meta_content($uploadedFile) ?>
                            </div>

                            <?php if (_can_read_file($uploadedFile)) { ?>
                                <div class="extra">
                                    <button class="ui labeled icon button clickable" data-url="file.php?id=<?= $uploadedFile->uploaded_file_id ?>"><i class="download icon"></i> Download</button>
                                </div>
                                <div class="ui positive right labeled icon button clickable" onclick="$('.share<?= $uploadedFileIndex ?>').modal('show')">
                                    Share
                                    <i class="share icon"></i>
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

<?php

function _get_meta_content(UploadedFile $uploadedFile)
{
    ob_start();
?>
    <span><?= human_readable_bytes($uploadedFile->filesize) ?> <?= $uploadedFile->mimetype ?></span>
    <span>Uploaded at <?= $uploadedFile->uploaded_at ?></span>
    <?php if ($uploadedFile->voucher_id > 0) { ?>
        <span>Voucher: <?= Voucher::getOne($uploadedFile->voucher_id)->voucher_code ?></span>
    <?php } ?>
<?php
    $meta = ob_get_contents();
    ob_end_clean();
    return $meta;
}

function _can_read_file(UploadedFile $uploadedFile)
{
    $app = \Fuppi\App::getInstance();
    $user = $app->getUser();

    if ($voucher = $app->getVoucher()) {
        if ($uploadedFile->voucher_id === $voucher->voucher_id) {
            return true;
        }
        if ($user->hasPermission(VoucherPermission::UPLOADEDFILES_LIST_ALL)) {
            return true;
        }
    } else {
        return $user->hasPermission(UserPermission::UPLOADEDFILES_READ);
    }

    return false;
}
