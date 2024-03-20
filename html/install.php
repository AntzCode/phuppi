<?php

$sourceDir = file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'fuppi.php')
    ? __DIR__ . DIRECTORY_SEPARATOR . 'src'
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';

require($sourceDir . DIRECTORY_SEPARATOR . 'config.php');

if (!empty($_POST)) {
    ob_start();

    require($sourceDir . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'migrate.php');
    rename(__DIR__ . DIRECTORY_SEPARATOR . 'install.php', $sourceDir . DIRECTORY_SEPARATOR . 'install.php');
    ob_get_contents();
    ob_end_clean();
    require($sourceDir . DIRECTORY_SEPARATOR . 'fuppi.php');
    logout();
    redirect('/');
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuppi</title>
    <link rel="stylesheet" type="text/css" href="/assets/fomantic/dist/semantic.min.css">
    <script src="/assets/axios/axios.min.js"></script>
    <script src="/assets/jquery/jquery-3.1.1.min.js"></script>
    <script src="/assets/fomantic/dist/semantic.min.js"></script>
    <link rel="stylesheet" type="text/css" href="/assets/css/fuppi.css">
    <script src="/assets/js/fuppi.js"></script>
</head>

<body>
    <header class="ui grid padded page-header middle aligned">
        <div class="three wide column left aligned clickable" data-url="/">
            <img src="/assets/images/logo/phuppi/phuppi-logo-horizontal-slogan.svg" width="280" height="122" />
        </div>
        <div class="thirteen wide column last bottom aligned right floated center aligned ui item horizontal-menu right aligned">

        </div>
    </header>
    <div class="ui container content page-content">

        <h1 class="header">Welcome to Phuppi!</h1>
        <p>A quick way to upload your files to a webserver.</p>

        <div class="ui grid padded">

            <div class="five wide column">
                <div class="ui list">
                    <div class="item">
                        <i class="green circle large check icon"></i>
                        <div class="content">Works with Standard PHP Hosting Services</div>
                    </div>
                    <div class="item">
                        <i class="green circle large check icon"></i>
                        <div class="content">Does not Require a Database (uses Sqlite3 + Local Filesystem)</div>
                    </div>
                    <div class="item">
                        <i class="green circle large check icon"></i>
                        <div class="content">Support for Multiple User Accounts</div>
                    </div>

                </div>
            </div>

            <div class="five wide column">
                <div class="ui list">
                    <div class="item">
                        <i class="green circle large check icon"></i>
                        <div class="content">Simple & Flexible Permissions Management</div>
                    </div>
                    <div class="item">
                        <i class="green circle large check icon"></i>
                        <div class="content">Generate Vouchers for Limited Access</div>
                    </div>
                    <div class="item">
                        <i class="green circle large check icon"></i>
                        <div class="content">Generate Temporary Links for Sharing Uploaded Files</div>
                    </div>
                </div>
            </div>

            <div class="five wide column">
                <div class="ui list">
                    <div class="item">
                        <i class="green circle large check icon"></i>
                        <div class="content">Generate Temporary Links for Sharing Uploaded Files</div>
                    </div>
                    <div class="item">
                        <i class="green circle large check icon"></i>
                        <div class="content">Supports AWS S3 Buckets for Large File Uploads</div>
                    </div>
                </div>
            </div>
        </div>

        <h3 class="header"> Create your Administrator Login to get started:</h3>

        <div class="ui bottom attached segment">

            <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="POST">
                <div class="field <?= (!empty($errors['username'] ?? []) ? 'error' : '') ?>">
                    <label for="username"><i class="user icon"></i>Username: </label>
                    <input id="username" type="text" name="username" value="<?= $_POST['username'] ?? '' ?>" />
                    <small>*This is the username you will use when logging in.</small>
                </div>
                <div class="field <?= (!empty($errors['password'] ?? []) ? 'error' : '') ?>">
                    <label for="password"><i class="key icon"></i>Password: </label>
                    <input id="password" type="password" name="password" value="<?= $_POST['password'] ?? '' ?>" />
                    <small>*This is the password that stops hackers from logging in to your account. Make sure it's a strong password.</small>
                </div>

                <button class="ui right labeled icon green button" type="submit"><i class="check icon left"></i> Install Fuppi!</button>

            </form>
        </div>


    </div>
    <footer class="page-footer">
        <div class="ui container two column grid">
            <span class="column">&copy; <?= date('Y') ?> <a href="http://www.phuppi.com">Phuppi</a>, a "File-Uppie" thing.</span>
            <span class="column right aligned">v<?= $fuppiConfig['fuppi_version'] ?></span>
        </div>
    </footer>
</body>

</html>