<?php

use Fuppi\User;

require('fuppi.php');

$errors = [];

if (!empty($_POST)) {

    if ($authenticatingUser = User::findByUsername($_POST['username'] ?? '')) {

        if (!empty("{$authenticatingUser->password}") && password_verify($_POST['password'], $authenticatingUser->password)) {
            if (!is_null($authenticatingUser->disabled_at)) {
                fuppi_add_error_message('Your account has been disabled.');
                
            } else {
                $user->setData($authenticatingUser->getData());
                redirect($_GET['redirectAfterLogin'] ?? '/');
            }
        } else {
            $errors['password'] = ['Password is incorrect'];
            sleep(5);
        }
    } else {
        $errors['username'] = ['User not found'];
        sleep(5);
    }

    if (!empty($errors)) {
        fuppi_add_error_message($errors);
    }
}
?>
<div class="content">

    <div class="ui segment">

        <div class="ui top attached label"><i class="user icon"></i> <label for="username">Authentication</label></div>

        <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">

            <div class="field <?= (!empty($errors['username'] ?? []) ? 'error' : '') ?>">
                <label for="username">Username: </label>
                <input id="username" type="text" name="username" value="<?= $_POST['username'] ?? '' ?>" />
            </div>

            <div class="field <?= (!empty($errors['password'] ?? []) ? 'error' : '') ?>">
                <label for="password">Password: </label>
                <input id="password" type="password" name="password" value="<?= $_POST['password'] ?? '' ?>" />
            </div>

            <button class="ui right labeled icon green button" type="submit"><i class="user icon left"></i> Log In</button>

        </form>

    </div>

</div>