<?php

require('../src/fuppi.php');

$errors = [];

if (!empty($_POST)) {

    $statement = $pdo->prepare("SELECT `user_id`, `username`, `password` FROM `fuppi_users` WHERE `username` = :username");

    if ($statement->execute(['username' => $_POST['username']]) && $row = $statement->fetch(PDO::FETCH_ASSOC)) {

        if (password_verify($_POST['password'], $row['password'])) {
            $user->setData($row);
            redirect($_GET['redirectAfterLogin'] ?? '/');
        } else {
            $errors['password'] = ['Password is incorrect'];
            sleep(5);
        }
    } else {
        $errors['username'] = ['User not found'];
        sleep(5);
    }
}
?>
<div class="content">

    <?php if (!empty($errors)) {
        fuppi_component('errorMessage', ['errors' => $errors]);
    } ?>

    <div class="ui segment">

        <div class="ui top attached label"><i class="user icon"></i> <label for="files">Authentication</label></div>

        <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">

            <div class="field <?= (!empty($errors['username'] ?? []) ? 'error' : '') ?>">
                <label for="username">Username: </label>
                <input id="username" type="text" name="username" value="<?= $_POST['username'] ?? '' ?>" />
            </div>

            <div class="field <?= (!empty($errors['password'] ?? []) ? 'error' : '') ?>">
                <label for="password">Password: </label>
                <input id="password" type="password" name="password" value="<?= $_POST['password'] ?? '' ?>" />
            </div>

            <button class="ui right labeled icon button" type="submit"><i class="user icon left"></i> Log In</button>

        </form>

    </div>

</div>