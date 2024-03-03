<?php

require('../src/fuppi.php');

if ($user->getId() <= 0) {
    redirect('/login.php?redirectAfterLogin=' . urlencode('/'));
}

$errors = [];

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

        if (!file_exists($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $user->getUsername())) {
            mkdir($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $user->getUsername(), 0777, true);
        }

        $storedFilepath = $config->uploadedFilesPath . DIRECTORY_SEPARATOR . $user->getUsername() . DIRECTORY_SEPARATOR . $sanitizedFilename;
        move_uploaded_file($_FILES['files']['tmp_name'][$k], $storedFilepath);

        $statement = $pdo->prepare("INSERT INTO `fuppi_uploaded_files` (`user_id`, `filename`, `filesize`, `mimetype`, `extension`) VALUES (:user_id, :filename, :filesize, :mimetype, :extension)");
        $statement->execute([
            'user_id' => $user->getId(),
            'filename' => $sanitizedFilename,
            'filesize' => filesize($storedFilepath),
            'mimetype' => mime_content_type($storedFilepath),
            'extension' => pathinfo($filename, PATHINFO_EXTENSION)
        ]);
    }

    if (empty($errors)) {
        // prevent re-submit on page refresh
        redirect('/');
    }
}

$statement = $pdo->prepare("SELECT `file_id`, `filename`, `filesize`, `mimetype`, `extension` FROM `fuppi_uploaded_files` WHERE `user_id` = :user_id");
$statement->execute(['user_id' => $user->getId()]);
$uploadedFiles = $statement->fetchAll();

?>
<div class="content">

    <h2>Welcome back, <?= $user->getUsername() ?>!</h2>

    <?php if (!empty($errors)) { ?>
        <p>
            <strong style="display: inline-block; border: solid 0.25em red; color: red; padding: 1em;">
                <?= !array_walk($errors, function ($errors) {
                    echo implode(', ', $errors);
                }) ?>
            </strong>
        </p>
    <?php } ?>

    <div style="border: 0.125em solid gray; padding: 1em;">
        <form action="/" method="post" enctype="multipart/form-data">
            <h2>Add Files</h2>
            <div class="form-group">
                <label for="files">Files</label>
                <input type="file" name="files[]" multiple="multiple" />
            </div>
            <div class="form-group submit">
                <button type="submit">Upload now</button>
            </div>
        </form>
    </div>

    <hr />

    <div style="border: 0.125em solid gray; padding: 1em;">
        <h2>Your Files</h2>
        <table>
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Filesize</th>
                    <th>Mime Type</th>
                    <th>Extension</th>
                    <th>Download</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($uploadedFiles as $uploadedFile) { ?>
                    <tr>
                        <td><a href="file.php?id=<?= $uploadedFile['file_id'] ?>&1"><?= $uploadedFile['filename'] ?></a></td>
                        <td><?= $uploadedFile['filesize'] ?></td>
                        <td><?= $uploadedFile['mimetype'] ?></td>
                        <td><?= $uploadedFile['extension'] ?></td>
                        <td><a href=""></a></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

</div>