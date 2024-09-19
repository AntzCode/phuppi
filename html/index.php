<?php

use Aws\S3\PostObjectV4;

use Fuppi\ApiResponse;
use Fuppi\FileSystem;
use Fuppi\SearchQuery;
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

$pageSizes = [10, 25, 50, 100, 250, 500, 1000];
$defaultPageSize = 10;

$orderBys = [
    '`filesize` ASC' => 'Size (smallest)',
    '`filesize` DESC' => 'Size (largest)',
    '`uploaded_at` ASC' => 'Date (oldest) ',
    '`uploaded_at` DESC' => 'Date (newest) ',
    '`display_filename` COLLATE NOCASE ASC' => 'Filename (up)',
    '`display_filename` COLLATE NOCASE DESC' => 'Filename (down)'
];
$defaultOrderBy = array_keys($orderBys)[3];

if (!empty($_GET['userId'])) {
    if ($user->hasPermission(UserPermission::USERS_READ)) {
        $profileUser = User::getOne($_GET['userId']) ?? $user;
    }
}

if (!empty($_POST)) {
    switch ($_POST['_method'] ?? 'post') {
        case 'delete':
            if (_can_delete_files()) {
                $apiResponse = new ApiResponse();
                foreach ((json_decode($_POST['fileIds']) ?? [($_POST['fileId'] ?? 0)]) as $fileId) {
                    $uploadedFile = UploadedFile::getOne($fileId);
                    $fileUser = $uploadedFile->getUser();
                    if ($uploadedFile->user_id !== $profileUser->user_id) {
                        if ($_POST['ajax']) {
                            $apiResponse->throwException($uploadedFile->uploaded_file_id . ' does not belong to this user');
                        }
                        throw new Exception($uploadedFile->uploaded_file_id . ' does not belong to this user');
                    } else {
                        if ($app->getVoucher()) {
                            if ($app->getVoucher()->voucher_id !== $uploadedFile->voucher_id) {
                                if ($app->getVoucher()->hasPermission(VoucherPermission::UPLOADEDFILES_LIST_ALL)
                                && $app->getVoucher()->hasPermission(VoucherPermission::UPLOADEDFILES_DELETE)) {
                                    // permitted to delete all files of the user
                                } else {
                                    // not permitted to delete the file that was not uploaded by the voucher
                                    if ($_POST['ajax']) {
                                        $apiResponse->throwException($uploadedFile->uploaded_file_id . ' does not belong to this voucher');
                                    }
                                    throw new Exception($uploadedFile->uploaded_file_id . ' does not belong to this voucher');
                                }
                            }
                        }
                    }

                    if ($fileSystem->isRemote()) {
                        try {
                            $fileSystem->deleteObject($config->remote_uploaded_files_prefix . '/' . $fileUser->username . '/' . $uploadedFile->filename);

                            UploadedFile::deleteOne($fileId);
                        } catch (\Exception $e) {
                            if ($_POST['ajax']) {
                                $apiResponse->throwException($e->getMessage());
                            }
                        }
                    } else {
                        if (file_exists($config->uploaded_files_path . DIRECTORY_SEPARATOR . $fileUser->username . DIRECTORY_SEPARATOR . $uploadedFile->filename)) {
                            unlink($config->uploaded_files_path . DIRECTORY_SEPARATOR . $fileUser->username . DIRECTORY_SEPARATOR . $uploadedFile->filename);
                        }
                        UploadedFile::deleteOne($fileId);
                    }
                }
            }

            if ($_POST['ajax']) {
                $apiResponse->data =$_POST['fileIds'];
                $apiResponse->sendResponse();
            } else {
                redirect($_SERVER['REQUEST_URI']);
            }

            break;

        case 'post':

            switch ($_POST['_action'] ?? '') {
                case 'setPageSize':
                    $apiResponse = new ApiResponse();
                    if (in_array($_POST['pageSize'], $pageSizes)) {
                        $profileUser->setSetting('filesPageSize', $_POST['pageSize']);
                        $apiResponse->sendResponse();
                    } else {
                        $apiResponse->throwException('Invalid page size');
                    }
                    break;
                case 'setOrderBy':
                    $apiResponse = new ApiResponse();
                    if (array_key_exists($_POST['orderBy'], $orderBys)) {
                        $profileUser->setSetting('filesOrderBy', $_POST['orderBy']);
                        $apiResponse->sendResponse();
                    } else {
                        $apiResponse->throwException('Invalid sort order');
                    }
                    break;
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

                    try {
                        $filename = $_POST['filename'];
                        $filesize = $_POST['filesize'];
                        $filetype = $_POST['filetype'];

                        $remoteClient = $fileSystem->getClient();

                        $sanitizedFilename = UploadedFile::generateUniqueFilename($filename);
                        $requestKey = $config->remote_uploaded_files_prefix . '/' . $user->username . '/' . $sanitizedFilename;
                        $storedFilepath = $config->remote_uploaded_files_prefix . '/' . $user->username . '/' . $sanitizedFilename;

                        $options = [
                            ['bucket' => $config->getSetting('remote_files_container')],
                            ['key' => $requestKey],
                            ['content-type' => $filetype]
                        ];

                        $formInputs = ['key' => $requestKey];
                        $expires = time() + (int) $config->getSetting('remote_files_token_lifetime_seconds');

                        $postObject = new PostObjectV4(
                            $remoteClient,
                            $config->getSetting('remote_files_container'),
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
                    } catch (\Exception $e) {
                        $apiResponse = new ApiResponse();
                        $apiResponse->throwException($e->getMessage());
                    }

                    break;

                case 'saveS3UploadedFile':

                    $sanitizedFilename = UploadedFile::generateUniqueFilename($_POST['filename']);

                    try {
                        $meta = $fileSystem->getObjectMetaData($config->remote_uploaded_files_prefix . '/' . $user->username . '/' . $sanitizedFilename);

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

// fetch the search results
$pageNum = $_GET['page'] ?? 1;
$pageSize = $profileUser->getSetting('filesPageSize') ?? $defaultPageSize;
$orderBy = $profileUser->getSetting('filesOrderBy') ?? $defaultOrderBy;

$searchTerm = $_GET['searchTerm'] ?? '';

$searchQuery = (new SearchQuery())
->where('user_id', $profileUser->user_id)
->orderBy($orderBy)
->limit($pageSize)->offset(($pageNum - 1) * $pageSize);

if (strlen($searchTerm) > 0) {
    $searchTermQuery = new SearchQuery();
    $searchTermQuery->where('filename', '%' . $searchTerm . '%', SearchQuery::LIKE);
    $searchTermQuery->where('display_filename', '%' . $searchTerm . '%', SearchQuery::LIKE);
    $searchTermQuery->where('notes', '%' . $searchTerm . '%', SearchQuery::LIKE);
    $searchTermQuery->setConcatenator(SearchQuery::OR);
    $searchQuery->append($searchTermQuery);
}

if ($voucher = $app->getVoucher()) {
    if (!$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_LIST_ALL)) {
        // only select the files that have been uploaded by this voucher
        $searchQuery->where('voucher_id', $voucher->voucher_id);
    }
}

$searchResult = UploadedFile::search($searchQuery);

$uploadedFiles = $searchResult['rows'];

$resultSetStart = (($pageNum-1) * $pageSize) + 1;
$resultSetEnd = ((($pageNum-1) * $pageSize) + count($uploadedFiles));

?>


<h2>Welcome back, <?= $profileUser->username ?>!</h2>

<?php if ($user->hasPermission(UserPermission::UPLOADEDFILES_PUT)) { ?>

    <form id="uploadFilesForm" disabled class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post" enctype="multipart/form-data">

        <div class="ui segment <?= ($user->user_id !== $profileUser->user_id ? 'disabled' : '') ?> ">

            <h3 class="header">
                <i class="upload icon"></i> 
                <label for="files">Upload Some Files
                    <?php if ($fileSystem->isRemote() < 1) { ?>
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

                        <?php if ($fileSystem->isRemote() > 0) { ?>
                            <div class="ui container center aligned">
                                <script>
                                    async function processRemoteUpload() {

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

                                                console.log('the response is ', response);

                                                const data = {
                                                    ...response.data.formInputs,
                                                    'Content-Type': file.type
                                                }

                                                let remoteFormData = new FormData();
                                                for (const name in data) {
                                                    remoteFormData.append(name, data[name]);
                                                }
                                                remoteFormData.append('file', file);

                                                await axios.post(response.data.formAttributes.action, remoteFormData).then((response) => {
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
                                                    alert(error.response?.data?.message || error.message);
                                                    console.log(error);
                                                    $('#uploadFilesForm .submit.button').prop('disabled', false);
                                                });

                                            }).catch((error) => {
                                                alert(error.response?.data?.message || error.message);
                                                console.log(error);
                                                $('#uploadFilesForm .submit.button').prop('disabled', false);
                                            });

                                        }

                                    }
                                </script>
                                <button <?= ($user->user_id !== $profileUser->user_id ? 'disabled="disabled"' : '') ?> class="ui primary right labeled icon submit button" type="button" onclick="(this.disabled='disabled', processRemoteUpload())"><i class="upload icon right"></i> Upload to Cloud</button>
                            </div>

                        <?php } else { ?>

                            <div class="ui container center aligned">
                                <button <?= ($user->user_id !== $profileUser->user_id ? 'disabled="disabled"' : '') ?> class="ui primary right labeled icon submit button" type="submit"><i class="upload icon right"></i> Upload to Server</button>
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
            <label> Your Uploaded Files 
                <?php if ($searchResult['count'] > 0) { ?>    
                    (<?= $resultSetStart ?> - <?= $resultSetEnd ?> of <?= $searchResult['count'] ?>)
                <?php } ?>
            </label>
        </h3>

        <div class="ui stackable menu">
            <?php if (_can_multiple_select()) { ?>
                <div class="clickable item multi-select-all" data-multi-select-item-selector=".multi-select-item.uploaded-file">
                    <i class="square outline large primary icon"></i>
                    <label>Select All / Deselect All</label>
                </div>
                <div class="ui dropdown item clickable">
                    <label>With <span class="multi-select-count"></span> Selected (<span class="multi-select-size">0B</span>)</label>
                    <i class="dropdown icon"></i>
                    <div class="menu">
                        <?php if (_can_multiple_download()) { ?>
                            <div class="item multi-select-action" data-multi-select-action="download">
                                <i class="download icon"></i>        
                                <label>Zip &amp; Download</label>
                            </div>
                        <?php } ?>
                        <div class="item multi-select-action"
                            data-multi-select-action-url="<?= $_SERVER['REQUEST_URI'] ?>"
                            data-multi-select-action-callback="refresh"
                            data-multi-select-action="delete">
                            <i class="trash icon"></i>        
                            <label>Delete Selected</label>
                        </div>
                    </div>
                </div>
            <?php } ?>
            <div class="right menu">
                <div class="item">
                    <div class="ui icon input">
                        <script type="text/javascript">
                            function performSearch(){
                                window.location="<?= basename(__FILE__) ?>?searchTerm=" + $('input[name=searchTerm]').val();
                            }
                        </script>
                        <input type="text" name="searchTerm" placeholder="Search..." 
                            onkeydown="(() => {if(event.key==='Enter'){performSearch();}})()"
                            value="<?= $_GET['searchTerm'] ?? '' ?>" />
                        <i class="search link icon" onClick="performSearch()" title="Perform search"></i>
                    </div>
                </div>
                <div class="ui dropdown item clickable" title="Sorting order">
                    <label><?= $orderBys[$orderBy] ?></label>
                    <i class="dropdown icon"></i>
                    <div class="ui labeled icon menu">
                        <script type="text/javascript">
                            async function setOrderBy(newOrderBy){
                                let formData = new FormData();
                                formData.append('_action', 'setOrderBy');
                                formData.append('orderBy', newOrderBy);
                                await axios.post('<?= basename(__FILE__) ?>', formData).then((response) => {
                                    window.location=window.location;
                                }).catch((error) => {
                                    console.log(error);
                                });
                            }
                        </script>
                        <?php foreach (array_keys($orderBys) as $_orderBy) { ?>
                            <div class="item" onClick="setOrderBy('<?= $_orderBy ?>')">
                                <?= $orderBys[$_orderBy] ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
                <div class="ui dropdown item clickable" title="Rows per page">
                    <label><?= $pageSize ?> per page</label>
                    <i class="dropdown icon"></i>
                    <div class="ui stackable menu">
                        <script type="text/javascript">
                            async function setPageSize(newPageSize){
                                let formData = new FormData();
                                formData.append('_action', 'setPageSize');
                                formData.append('pageSize', newPageSize);
                                await axios.post('<?= basename(__FILE__) ?>', formData).then((response) => {
                                    window.location=window.location;
                                }).catch((error) => {
                                    console.log(error);
                                });
                            }
                        </script>
                        <?php foreach ($pageSizes as $_pageSize) { ?>
                            <div class="item" onClick="setPageSize(<?= $_pageSize ?>)"><?= $_pageSize ?></div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="ui divider"></div>

        <?php if (empty($uploadedFiles)) { ?>

            <div class="ui content">
                <p>- Empty -</p>
            </div>

        <?php } else { ?>

            <div class="ui divided items">

                <?php foreach ($uploadedFiles as $uploadedFileIndex => $uploadedFile) { ?>

                    <div class="ui item" style="position: relative">

                        <?php if (_can_delete_files()) { ?>
                            <button class="red ui top right attached round label raised clickable-confirm" style="z-index: 1;" data-confirm="Are you sure you want to delete this file?" data-action="(e) => document.getElementById('deleteUploadedFileForm<?= $uploadedFileIndex ?>').submit()">
                                <i class="trash icon"></i> Delete
                            </button>
                        <?php } ?>

                        <?php if (_can_read_file($uploadedFile) && _can_preview_file($uploadedFile)) {  ?>

                            <div class="ui modal preview<?= $uploadedFileIndex ?>">

                                <i class="close icon"></i>

                                <div class="header">
                                    Preview
                                </div>

                                <div class="image content">
                                    <?php if (in_array($uploadedFile->mimetype, $config->image_mime_types)) { ?>
                                        <img class="ui centered massive image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>">
                                    <?php } else if (in_array($uploadedFile->mimetype, $config->video_mime_types)) { ?>
                                        <video class="ui centered massive image" 
                                            id="preview-video-<?= $uploadedFile->uploaded_file_id ?>"
                                            poster="/assets/images/filetype-icons/<?= $uploadedFile->extension ?>.png"
                                            controls preload="metadata"
                                        >
                                            <source src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>" type="<?= $uploadedFile->mimetype ?>" />
                                        </video>
                                    <?php } ?>
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
                                            <?php if (_can_read_file($uploadedFile) && _can_preview_file($uploadedFile)) { ?>
                                                <?php if (in_array($uploadedFile->mimetype, $config->image_mime_types)) { ?>
                                                    <img class="tiny rounded image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>" />
                                                <?php } else if (in_array($uploadedFile->mimetype, $config->video_mime_types)) { ?>
                                                    <video class="ui centered massive image" 
                                                        id="share-video-<?= $uploadedFile->uploaded_file_id ?>"
                                                        poster="/assets/images/filetype-icons/<?= $uploadedFile->extension ?>.png"
                                                        preload="none"
                                                    >
                                                        <source src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>#t=0.1" type="<?= $uploadedFile->mimetype ?>" />
                                                    </video>
                                                <?php } ?>
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

                                                <div class="ui grid">
                                                    <div class="sixteen wide column ui labeled ticked slider attached computer tablet only" id="sharableLinkSlider<?= $uploadedFileIndex ?>"></div>
                                                    <div class="sixteen wide column mobile only">
                                                        <div class="ui dropdown" id="sharableLinkDropdown<?= $uploadedFileIndex ?>">
                                                            <input type="hidden" name="sharableLinkDropdown<?= $uploadedFileIndex ?>" value="<?= $_POST['valid_for'] ?? array_keys($config->token_valid_for_options)[3], array_keys($config->token_valid_for_options) ?>" />
                                                            <label><?= array_values($config->token_valid_for_options)[(array_search($_POST['valid_for'] ?? array_keys($config->token_valid_for_options)[3], array_keys($config->token_valid_for_options)))] ?></label>
                                                            <i class="dropdown icon"></i>
                                                            <div class="menu">
                                                                <?php foreach ($config->token_valid_for_options as $k => $v) { ?>
                                                                    <div class="item" data-value="<?= $k ?>">
                                                                        <?= $v ?>
                                                                    </div>
                                                                <?php } ?> 
                                                            </div>
                                                        </div>
                                                    </div> 

                                                </div>
                                                
                                                <script type="text/javascript">
                                                    var tokenValidForOptions = JSON.parse('<?= json_encode($config->token_valid_for_options) ?>');
                                                    $('#sharableLinkDropdown<?= $uploadedFileIndex ?>').dropdown({
                                                        allowAdditions: true,
                                                        allowReselection: true
                                                    }).on('change', function (e) {
                                                        $('#sharableLinkDropdown<?= $uploadedFileIndex ?> label').text(tokenValidForOptions[e.target.value]);
                                                        let formData = new FormData();
                                                        formData.append('_action', 'createSharableLink');
                                                        formData.append('fileId', <?= $uploadedFile->uploaded_file_id ?>);
                                                        formData.append('validFor', e.target.value);
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
                                                    });
                                                    $('#sharableLinkSlider<?= $uploadedFileIndex ?>').slider({
                                                        min: 0,
                                                        max: <?= count($config->token_valid_for_options) - 1 ?>,
                                                        start: <?= (array_search($_POST['valid_for'] ?? array_keys($config->token_valid_for_options)[3], array_keys($config->token_valid_for_options))) ?>,
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

                                                        <div class="ui large text">Expires at: <span id="expiresAt<?= $uploadedFileIndex ?>"></span></div>
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
                            <div class="ui image multi-select-item uploaded-file" style="padding: 1em; align-self: center;"
                            data-multi-select-item-id="<?= $uploadedFile->uploaded_file_id ?>" 
                            data-multi-select-item-size="<?= $uploadedFile->filesize ?>">
                                <i class="clickable large primary square outline icon"></i>

                            </div>
                        <?php } ?>

                        <?php if (_can_read_file($uploadedFile) && _can_preview_file($uploadedFile)) { ?>
                            <div class="ui tiny rounded image clickable raised" onclick="$('.preview<?= $uploadedFileIndex ?>').modal({onHide: () => {$('#preview-video-<?= $uploadedFile->uploaded_file_id ?>').trigger('pause')}}).modal('show')">
                                
                                <?php if (in_array($uploadedFile->mimetype, $config->image_mime_types)) { ?>
                                    <img class="tiny rounded image" src="file.php?id=<?= $uploadedFile->uploaded_file_id ?>" />
                                <?php } else if (in_array($uploadedFile->mimetype, $config->video_mime_types)) { ?>
                                    <img src="/assets/images/filetype-icons/<?= $uploadedFile->extension ?>.png" />
                                <?php } ?>

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

                            <div class="header" style="">
                                <span id="uploadedFileDisplayFilename<?= $uploadedFile->uploaded_file_id ?>" style="word-break: break-all;"><?= $uploadedFile->display_filename ?></span>
                                <?php if (_can_write_file_meta($uploadedFile)) { ?>
                                    <script type="text/javascript">
                                        $('.meta<?= $uploadedFile->uploaded_file_id ?>').modal('setting', {
                                            maxWidth: '80%',
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
                                <?php if (_can_delete_files()) { ?>
                                    <form id="deleteUploadedFileForm<?= $uploadedFileIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                                        <input name="_method" type="hidden" value="delete" />
                                        <input type="hidden" name="fileId" value="<?= $uploadedFile->uploaded_file_id ?>" />
                                    </form>
                                <?php } ?>
                            </div>

                        </div>

                    </div>

                <?php } ?>

            </div>

        <?php } ?>

    </div>

    <div style="overflow-x: scroll">
        <div class="ui pagination menu">
            <?php for ($i = 0; $i<$searchResult['count']/$pageSize; $i++) { ?>
                <a href="?page=<?= $i+1 ?>&searchTerm=<?= $searchTerm ?>" class="item <?= ($i+1 === (int) $pageNum ? 'active' : '') ?>">
                    <?= $i+1 ?>
                </a>
            <?php } ?>
        </div>
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

function _can_delete_files()
{
    $app = \Fuppi\App::getInstance();
    $user = $app->getUser();

    if ($voucher = $app->getVoucher()) {
        if ($user->hasPermission(VoucherPermission::UPLOADEDFILES_DELETE)) {
            return true;
        }
    } else {
        return $user->hasPermission(UserPermission::UPLOADEDFILES_DELETE);
    }

    return false;
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

function _can_preview_file(UploadedFile $uploadedFile)
{
    $app = \Fuppi\App::getInstance();
    $config = $app->getConfig();
    return in_array($uploadedFile->mimetype, array_merge(
        $config->image_mime_types,
        $config->video_mime_types
    ));
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

function _can_multiple_download()
{
    $config = \Fuppi\App::getInstance()->getConfig();
    switch ($config->getSetting('file_storage_type')) {
        case FileSystem::AWS_S3:
            return !empty($config->getSetting('aws_lambda_multiple_zip_function_name')) && FileSystem::isValidRemoteEndpoint();
        case FileSystem::SERVER_FILESYSTEM:
            return class_exists('ZipArchive');
        case FileSystem::DIGITAL_OCEAN_SPACES:
            return !empty($config->getSetting('do_functions_multiple_zip_endpoint')) && !empty($config->getSetting('do_functions_multiple_zip_api_token')) && FileSystem::isValidRemoteEndpoint();
    }
}

function _can_multiple_select()
{
    $app = \Fuppi\App::getInstance();
    $user = $app->getUser();
    $config = $app->getConfig();

    if ($voucher = $app->getVoucher()) {
        if ($user->hasPermission(VoucherPermission::UPLOADEDFILES_READ)) {
            return true;
        }
    } else {
        return $user->hasPermission(UserPermission::UPLOADEDFILES_READ);
    }

    return false;
}
