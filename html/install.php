<?php

if (!empty($_POST)) {
    ob_start();
    require(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'migrate.php');
    rename(__DIR__ . DIRECTORY_SEPARATOR . 'install.php', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'install.php');
    ob_get_contents();
    ob_end_clean();
    require(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'fuppi.php');
    logout();
    redirect('/');
}

?>
<h1>Welcome to Fuppi!</h1>
<p>fuppi: "File-Uppie". A quick way to upload your files to a webserver.</p>
<p>Create your administrator login to get started:</p>
<form action="<?= $_SERVER['REQUEST_URI'] ?>" method="POST">
    <div class="form-group <?= (!empty($errors['username'] ?? []) ? 'error' : '') ?>">
        <label for="username">Username: </label>
        <input id="username" type="text" name="username" value="<?= $_POST['username'] ?? '' ?>" />
        <span>*This is the username you will use when logging in.</span>
    </div>
    <div class="form-group <?= (!empty($errors['password'] ?? []) ? 'error' : '') ?>">
        <label for="password">Password: </label>
        <input id="password" type="password" name="password" value="<?= $_POST['password'] ?? '' ?>" />
        <span>*This is the password that stops hackers from logging in to your account. Make sure it's a strong password.</span>
    </div>
    <div class="form-group submit">
        <button type="submit">Install Fuppi!</button>
    </div>
</form>