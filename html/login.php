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
    <?php if (!empty($errors)) { ?>
        <p>
            <strong style="display: inline-block; border: solid 0.25em red; color: red; padding: 1em;">
                <?= !array_walk($errors, function ($errors) {
                    echo implode(', ', $errors);
                }) ?>
            </strong>
        </p>
    <?php } ?>

    <form action="<?= $_SERVER['REQUEST_URI'] ?>" method="POST">
        <div class="form-group <?= (!empty($errors['username'] ?? []) ? 'error' : '') ?>">
            <label for="username">Username: </label>
            <input id="username" type="text" name="username" value="<?= $_POST['username'] ?? '' ?>" />
        </div>
        <div class="form-group <?= (!empty($errors['password'] ?? []) ? 'error' : '') ?>">
            <label for="password">Password: </label>
            <input id="password" type="password" name="password" value="<?= $_POST['password'] ?? '' ?>" />
        </div>
        <div class="form-group submit">
            <button type="submit">Log In</button>
        </div>
    </form>

</div>