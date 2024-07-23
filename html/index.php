<?php

use Aws\S3\PostObjectV4;

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
                $uploadedFile = UploadedFile::getOne($fileId);
                $fileUser = $uploadedFile->getUser();
                UploadedFile::deleteOne($fileId);
                if (file_exists($config->uploaded_files_path . DIRECTORY_SEPARATOR . $fileUser->username . DIRECTORY_SEPARATOR . $uploadedFile->filename)) {
                    unlink($config->uploaded_files_path . DIRECTORY_SEPARATOR . $fileUser->username . DIRECTORY_SEPARATOR . $uploadedFile->filename);
                }
                $sdk = new Aws\Sdk([
                    'region' => $config->getSetting('aws_s3_region'),
                    'credentials' =>  [
                        'key'    => $config->getSetting('aws_s3_access_key'),
                        'secret' => $config->getSetting('aws_s3_secret')
                    ]
                ]);
                $s3Client = $sdk->createS3();
                try {
                    $s3Client->deleteObject([
                        'Bucket' => $config->getSetting('aws_s3_bucket'),
                        'Key' => $config->s3_uploaded_files_prefix . '/' . $fileUser->username . '/' . $uploadedFile->filename
                    ]);
                } catch (\Exception $e) {
                }
            }
            redirect($_SERVER['REQUEST_URI']);
            break;

        case 'post':

            switch ($_POST['_action'] ?? '') {
                case 'writeFileMeta':
                    $apiResponse = new ApiResponse();
                    $uploadedFileId = (int) $_POST['fileId'];
                    if ($uploadedFile = UploadedFile::getOne($uploadedFileId)) {
                        if (!_can_write_file_meta($uploadedFile)) {
                            $apiResponse->throwException('Not permitted');
                        } else {
                            $sanitizedFilename = substr(trim($_POST['filename']), 0, 255);
                            $sanitizedNotes = substr(trim($_POST['notes']), 0, 1000000000-1024*32);

                            $statement = $pdo->prepare("UPDATE `fuppi_uploaded_files` SET `display_filename` = :display_filename, `notes` = :notes WHERE `uploaded_file_id` = :uploaded_file_id");

                            $statement->execute([
                                'uploaded_file_id' => $uploadedFileId,
                                'display_filename' => $sanitizedFilename,
                                'notes' => $sanitizedNotes
                            ]);

                            $uploadedFile = UploadedFile::getOne($uploadedFileId);
                            $uploadedFile->dropAwsPresignedUrl();

                            $apiResponse->data = $uploadedFile->getData();

                            $apiResponse->sendResponse();
                        }
                    } else {
                        $apiResponse->throwException('Invalid file id "' . $uploadedFileId . '"');
                    }
                    break;
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

                case 'getS3UploadPresignedURL':
                    $filename = $_POST['filename'];
                    $filesize = $_POST['filesize'];
                    $filetype = $_POST['filetype'];

                    $sanitizedFilename = UploadedFile::generateUniqueFilename($filename);
                    $requestKey = $config->s3_uploaded_files_prefix . '/' . $user->username . '/' . $sanitizedFilename;
                    $storedFilepath = $config->s3_uploaded_files_prefix . '/' . $user->username . '/' . $sanitizedFilename;

                    $sdk = new Aws\Sdk([
                        'region' => $config->getSetting('aws_s3_region'),
                        'credentials' =>  [
                            'key'    => $config->getSetting('aws_s3_access_key'),
                            'secret' => $config->getSetting('aws_s3_secret')
                        ]
                    ]);

                    $s3Client = $sdk->createS3();
                    $options = [
                        ['bucket' => $config->getSetting('aws_s3_bucket')],
                        ['key' => $requestKey],
                        ['content-type' => $filetype]
                    ];

                    $formInputs = ['key' => $requestKey];
                    $expires = time() + (int) $config->getSetting('aws_token_lifetime_seconds');

                    $postObject = new PostObjectV4(
                        $s3Client,
                        $config->getSetting('aws_s3_bucket'),
                        $formInputs,
                        $options,
                        $expires
                    );

                    $apiResponse = new ApiResponse();
                    $data = [
                        'formInputs' => $postObject->getFormInputs(),
                        'formAttributes' => $postObject->getFormAttributes()
                    ];
                    $apiResponse->sendResponse($data);

                    break;

                case 'saveS3UploadedFile':

                    $sanitizedFilename = UploadedFile::generateUniqueFilename($_POST['filename']);

                    $sdk = new Aws\Sdk([
                        'region' => $config->getSetting('aws_s3_region'),
                        'credentials' =>  [
                            'key'    => $config->getSetting('aws_s3_access_key'),
                            'secret' => $config->getSetting('aws_s3_secret')
                        ]
                    ]);

                    $s3Client = $sdk->createS3();

                    try {
                        $meta = $s3Client->headObject([
                            'Bucket' => $config->getSetting('aws_s3_bucket'),
                            'Key' => $config->s3_uploaded_files_prefix . '/' . $user->username . '/' . $sanitizedFilename
                        ]);

                        $statement = $pdo->prepare("INSERT INTO `fuppi_uploaded_files` (`user_id`, `voucher_id`, `filename`, `display_filename`, `filesize`, `mimetype`, `extension`, `notes`) VALUES (:user_id, :voucher_id, :filename, :display_filename, :filesize, :mimetype, :extension, :notes)");

                        $statement->execute([
                            'user_id' => $user->user_id,
                            'voucher_id' => $app->getVoucher()->voucher_id ?? 0,
                            'filename' => $sanitizedFilename,
                            'display_filename' => $_POST['filename'],
                            'filesize' => $meta['ContentLength'],
                            'mimetype' => $meta['ContentType'],
                            'extension' => pathinfo($sanitizedFilename, PATHINFO_EXTENSION),
                            'notes' => ''
                        ]);

                        $apiResponse = new ApiResponse();
                        $apiResponse->sendResponse(UploadedFile::getOne($pdo->lastInsertId()));
                    } catch (\Exception $e) {
                        $apiResponse = new ApiResponse();
                        $apiResponse->throwException($e->getMessage());
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

        $sanitizedFilename = UploadedFile::generateUniqueFilename($filename);

        if (!file_exists($config->uploaded_files_path . DIRECTORY_SEPARATOR . $user->username)) {
            mkdir($config->uploaded_files_path . DIRECTORY_SEPARATOR . $user->username, 0777, true);
        }

        $storedFilepath = $config->uploaded_files_path . DIRECTORY_SEPARATOR . $user->username . DIRECTORY_SEPARATOR . $sanitizedFilename;
        move_uploaded_file($_FILES['files']['tmp_name'][$k], $storedFilepath);

        $statement = $pdo->prepare("INSERT INTO `fuppi_uploaded_files` (`user_id`, `voucher_id`, `filename`, `display_filename`, `filesize`, `mimetype`, `extension`, `notes`) VALUES (:user_id, :voucher_id, :filename, :display_filename, :filesize, :mimetype, :extension, :notes)");

        $statement->execute([
            'user_id' => $user->user_id,
            'voucher_id' => $app->getVoucher()->voucher_id ?? 0,
            'filename' => $sanitizedFilename,
            'display_filename' => $filename,
            'filesize' => filesize($storedFilepath),
            'mimetype' => mime_content_type($storedFilepath),
            'extension' => pathinfo($filename, PATHINFO_EXTENSION),
            'notes' => ''
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

    <form id="uploadFilesForm" disabled class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post" enctype="multipart/form-data">

        <div class="ui segment <?= ($user->user_id !== $profileUser->user_id ? 'disabled' : '') ?> ">

            <h3 class="header">
                <i class="upload icon"></i> 
                <label for="files">Upload Some Files
                    <?php if ($config->getSetting('use_aws_s3') < 1) { ?>
                        (max <?= ini_get('post_max_size') ?>)
                    <?php } ?>
                </label>
            </h3>

            <div class="ui one cards">
                <div class="card">
                    <div class="content">
                        <div class="field">
                            <input <?= ($user->user_id !== $profileUser->user_id ? 'disabled="disabled"' : '') ?> id="files" type="file" name="files[]" placeholder="" multiple="multiple" />
                        </div>
                    </div>
                    <div class="extra content">

                        <?php if ($config->getSetting('use_aws_s3') > 0) { ?>
                            <div class="ui container center aligned">
                                <script>
                                    async function processS3Uploads() {

                                        if (document.getElementById('files').files.length < 1) {
                                            $('#uploadFilesForm .submit.button').prop('disabled', false);
                                            return;
                                        }

                                        for (let file of document.getElementById('files').files) {

                                            let formData = new FormData();
                                            formData.append('_action', 'getS3UploadPresignedURL');
                                            formData.append('filename', file.name);
                                            formData.append('filetype', file.type);
                                            formData.append('filesize', file.size);

                                            await axios.post('<?= $_SERVER['REQUEST_URI'] ?>', formData).then(async (response) => {

                                                const data = {
                                                    ...response.data.formInputs,
                                                    'Content-Type': file.type
                                                };

                                                let s3FormData = new FormData();
                                                for (const name in data) {
                                                    s3FormData.append(name, data[name]);
                                                }
                                                s3FormData.append('file', file);

                                                await axios.post(response.data.formAttributes.action, s3FormData).then((response) => {
                                                    formData.set('_action', 'saveS3UploadedFile');
                                                    axios.post('<?= $_SERVER['REQUEST_URI'] ?>', formData).then(async (response) => {
                                                        let lastFilename;
                                                        for (let _file of document.getElementById('files').files) {
                                                            lastFilename = _file.name;
                                                        }
                                                        if (lastFilename === file.name) {
                                                            window.location = window.location;
                                                        } else {
                                                            console.log('waiting for to finish after ' + file.name + ', lastfilename: ' + lastFilename);
                                                        }
                                                    });
                                                }).catch((error) => {
                                                    console.log(error);
                                                });

                                            }).catch((error) => {
                                                console.log(error);
                                            });

                                        }

                                    }
                                </script>
                                <button <?= ($user->user_id !== $profileUser->user_id ? 'disabled="disabled"' : '') ?> class="ui primary right labeled icon submit button" type="button" onclick="(this.disabled='disabled', processS3Uploads())"><i class="upload icon right"></i> Upload to S3</button>
                            </div>

                        <?php } else { ?>

                            <div class="ui container center aligned">
                                <button <?= ($user->user_id !== $profileUser->user_id ? 'disabled="disabled"' : '') ?> class="ui primary right labeled icon submit button" type="submit"><i class="upload icon right"></i> Upload</button>
                            </div>

                        <?php } ?>

                    </div>
                </div>
            </div>

        </div>

    </form>

<?php } ?>

<?php if ($user->hasPermission(UserPermission::UPLOADEDFILES_LIST)) { ?>

    <div class="ui segment">


        <h3 class="header">
            <i class="list icon"></i> 
            <label> Your Uploaded Files (<?= count($uploadedFiles) ?>)</label>
        </h3>

        <?php if (empty($uploadedFiles)) { ?>

            <div class="ui content">
                <p>- Empty -</p>
            </div>

        <?php } else { ?>

            <?php if (_can_multiple_select()) { ?>

                <div class="ui checkbox" style="display: flex; gap: 3em">
                    <input type="checkbox" value="" class="multiple-select-all" />
                    <label>Select/Deselect All</label>
                    <select class="ui dropdown" name="multiple-select-action">
                        <option value="">
                            -- With selected:
                        </option>
                        <option value="download">
                            Download
                        </option>
                    </select>
                </div>

            <?php } ?>

            <div class="ui divided items">

                <?php foreach ($uploadedFiles as $uploadedFileIndex => $uploadedFile) { ?>

                    <div class="ui item" style="position: relative">

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
                                    <img class="ui centered massive image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>">
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

                                <h3 class="header">
                                    <i class="share icon"></i> 
                                    <label>Share File</label>
                                </h3>

                                <div class="content ui items">

                                    <div class="item">

                                        <div class="image">
                                            <?php if (
                                                in_array($uploadedFile->mimetype, ['image/jpeg', 'image/png', 'image/giff'])
                                                && _can_read_file($uploadedFile)
                                            ) { ?>
                                                <img class="tiny rounded image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>" />
                                            <?php } else if (empty("{$uploadedFile->extension}")) { ?>
                                                <img src="/assets/images/filetype-icons/unknown.png" />
                                            <?php } else { ?>
                                                <img src="/assets/images/filetype-icons/<?= $uploadedFile->extension ?>.png" />
                                            <?php } ?>
                                        </div>

                                        <div class="content">

                                            <h4 class="header">
                                                <label><?= $uploadedFile->filename ?></label>
                                            </h4>

                                            <div class="meta">
                                                <?= _get_meta_content($uploadedFile) ?>
                                            </div>

                                            <div class="ui segment description">

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
                                                                    $('#sharableLink<?= $uploadedFileIndex ?>').val(response.data.url);
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

                                            <div class="extra content">
                                                <div class="ui form segment">

                                                    <div class="field">
                                                        <input type="text" id="sharableLink<?= $uploadedFileIndex ?>" />
                                                    
                                                        <div class="ui up pointing primary label icon">
                                                            <div class="ui round primary icon button copy-to-clipboard" id="sharableLinkCopyButton<?= $uploadedFileIndex ?>" data-content="" title="Copy to clipboard">
                                                                <i class="clickable copy icon"></i> Copy Link
                                                            </div>
                                                        </div>

                                                        <span class="ui large text">Expires at: <span id="expiresAt<?= $uploadedFileIndex ?>"></span></span>
                                                    </div>

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php } ?>

                        <?php if (_can_write_file_meta($uploadedFile)) { ?>
                        
                            <div class="ui modal meta<?= $uploadedFile->uploaded_file_id ?>">

                                <i class="close icon"></i>

                                <div class="header">
                                    File Info
                                </div>

                                <div class="content">
                                    
                                    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">

                                        <div class="field">
                                            <label for="meta_filename<?= $uploadedFile->uploaded_file_id ?>">File Name: </label>
                                            <div class="ui icon input">
                                                <input id="meta_filename<?= $uploadedFile->uploaded_file_id ?>" type="text" name="filename" value="<?= $uploadedFile->display_filename ?>" />
                                                <i class="inverted circular refresh link icon"
                                                    title="Use system filename"
                                                    onClick="(()=>{$('#meta_filename<?= $uploadedFile->uploaded_file_id ?>').val('<?= $uploadedFile->filename ?>')})()"></i>
                                            </div>
                                        </div>

                                        <div class="field <?= (!empty($errors['notes' . $uploadedFile->uploaded_file_id] ?? []) ? 'error' : '') ?>">
                                            <label for="meta_notes<?= $uploadedFile->uploaded_file_id ?>">Notes: </label>
                                            <textarea id="meta_notes<?= $uploadedFile->uploaded_file_id ?>" name="notes"><?= $uploadedFile->notes ?></textarea>
                                        </div>

                                    </form>

                                </div>

                                <div class="actions">
                                    <div class="ui cancel button">Cancel</div>
                                    <div class="ui positive approve button">Save</div>
                                </div>

                            </div>

                        <?php } ?>

                        <?php if (_can_multiple_select()) { ?>
                            <div class="ui image" style="padding: 1em; align-self: center;" >
                                <div class="vertical aligned middle">
                                    <input type="checkbox" value="<?= $uploadedFile->uploaded_file_id ?>" class="multiple-select" />
                                </div>
                            </div>
                        <?php } ?>

                        <?php if (
                            in_array($uploadedFile->mimetype, ['image/jpeg', 'image/png', 'image/giff'])
                            && _can_read_file($uploadedFile)
                        ) { ?>

                            <div class="ui tiny rounded image clickable raised" onclick="$('.preview<?= $uploadedFileIndex ?>').modal('show')">
                                <img class="tiny rounded image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>" />
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

                            <div class="header">
                                <span id="uploadedFileDisplayFilename<?= $uploadedFile->uploaded_file_id ?>"><?= $uploadedFile->display_filename ?></span>
                                <?php if (_can_write_file_meta($uploadedFile)) { ?>
                                    <script type="text/javascript">
                                        $('.meta<?= $uploadedFile->uploaded_file_id ?>').modal('setting', {
                                            onApprove: () => {
                                                let uploadedFileId = <?= $uploadedFile->uploaded_file_id ?>;
                                                
                                                let formData = new FormData();
                                                formData.append('_method', 'post');
                                                formData.append('_action', 'writeFileMeta');
                                                formData.append('fileId', uploadedFileId);
                                                formData.append('filename', $('#meta_filename'+uploadedFileId).val());
                                                formData.append('notes', $('#meta_notes'+uploadedFileId).val());
                                                    
                                                axios.post('<?= $_SERVER['REQUEST_URI'] ?>', formData).then((response) => {
                                                    $('#uploadedFileDisplayFilename'+response.data.uploaded_file_id).text(response.data.display_filename);
                                                    $('.uploadedFileNotes'+response.data.uploaded_file_id).text(response.data.notes);
                                                    $('#meta_filename'+uploadedFileId).val(response.data.display_filename);
                                                    $('#meta_notes'+uploadedFileId).val(response.data.notes);
                                                    $('.modal'+response.data.uploaded_file_id).modal('hide');
                                                }).catch((error) => {
                                                    if (error.response && error.response.data && error.response.data.message) {
                                                        alert(error.response.data.message);
                                                    } else {
                                                        console.log(error);
                                                    }
                                                });
                                            },
                                            onDeny: () => {
                                                $('#meta_filename<?= $uploadedFile->uploaded_file_id ?>').val($('#uploadedFileDisplayFilename<?= $uploadedFile->uploaded_file_id ?>').text());
                                            }
                                        });
                                    </script>
                                    <div class="ui right button circular icon clickable" onclick="$('.meta<?= $uploadedFile->uploaded_file_id ?>').modal('show')">
                                        <i class="edit icon" title="Edit File Info"></i>
                                        <input type="hidden" name="_action" value="userLogin" />
                                    </div>
                                <?php } ?>
                            </div>

                            <div class="meta">
                                <?= _get_meta_content($uploadedFile) ?>
                            </div>

                            <?php if (_can_read_file($uploadedFile)) { ?>
                                <div class="ui extra">
                                    <button class="ui labeled icon button clickable" data-url="file.php?id=<?= $uploadedFile->uploaded_file_id ?>"><i class="download icon"></i> Download</button>
                                    <div class="ui positive right labeled icon button clickable" onclick="$('.share<?= $uploadedFileIndex ?>').modal('show')">
                                        Share
                                        <i class="share icon"></i>
                                    </div>
                                </div>
                            <?php } ?>

                        </div>

                        <div class="right ui grid middle aligned">

                            <div class="one wide column">

                                <form id="deleteUploadedFileForm<?= $uploadedFileIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                                    <input name="_method" type="hidden" value="delete" />
                                    <input type="hidden" name="fileId" value="<?= $uploadedFile->uploaded_file_id ?>" />
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
    <p class="uploadedFileNotes<?= $uploadedFile->uploaded_file_id ?>"><?= $uploadedFile->notes ?></p>
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

function _can_write_file_meta(UploadedFile $uploadedFile)
{
    $app = \Fuppi\App::getInstance();
    $user = $app->getUser();

    if ($voucher = $app->getVoucher()) {
        if ($uploadedFile->voucher_id === $voucher->voucher_id) {
            return true;
        }
        if ($user->hasPermission(VoucherPermission::UPLOADEDFILES_PUT)) {
            return true;
        }
    } else {
        return $user->hasPermission(UserPermission::UPLOADEDFILES_PUT);
    }

    return false;
}


function _can_multiple_select()
{
    $app = \Fuppi\App::getInstance();
    $user = $app->getUser();
    $config = $app->getConfig();

    if (!$config->getSetting('use_aws_s3')) {
        if (!class_exists('ZipArchive')) {
            return false;
        }
    } else {
        if (empty($config->getSetting('aws_lambda_multiple_zip_function_name'))) {
            return false;
        }
    }

    if ($voucher = $app->getVoucher()) {
        if ($user->hasPermission(VoucherPermission::UPLOADEDFILES_READ)) {
            return true;
        }
    } else {
        return $user->hasPermission(UserPermission::UPLOADEDFILES_READ);
    }

    return false;
}
