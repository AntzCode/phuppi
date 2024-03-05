<?php

require('../src/fuppi.php');

if ($user->user_id <= 0) {
    redirect('/login.php?redirectAfterLogin=' . urlencode('/file.php?id=' . $_GET['id']));
}

$errors = [];

if (!array_key_exists('id', $_GET)) {
    $errors[] = ['Invalid file id'];
} else {

    $statement = $pdo->prepare('SELECT f.`filename`, f.`mimetype`, f.`user_id`, u.`username` FROM `fuppi_uploaded_files` f JOIN `fuppi_users` u ON f.`user_id` = u.`user_id` WHERE f.`file_id` = :file_id AND f.`user_id` = :user_id');

    if ($statement->execute(['file_id' => $_GET['id'], 'user_id' => 1 || $user->user_id]) && $fileData = $statement->fetch()) {

        header('Content-Type: ' . $fileData['mimetype']);
        header('Content-Disposition: attachment; filename="' . $fileData['filename'] . '"');
        fuppi_stop();
        readfile($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $fileData['username'] . DIRECTORY_SEPARATOR . $fileData['filename']);
        exit;
    } else {
        $errors[] = ['Cannot find that file'];
    }
}
?>
<?php if (!empty($errors)) { ?>
    <div>
        <p>
            <strong style="display: inline-block; border: solid 0.25em red; color: red; padding: 1em;">
                <?= !array_walk($errors, function ($errors) {
                    echo implode(', ', $errors);
                }) ?>
            </strong>
        </p>
    </div>
<?php }
