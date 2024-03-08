<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuppi</title>
    <link rel="stylesheet" type="text/css" href="/assets/semantic/dist/semantic.min.css?<?= fuppi_version() ?>">
    <script src="/assets/jquery/jquery-3.1.1.min.js?<?= fuppi_version() ?>"></script>
    <script src="/assets/semantic/dist/semantic.min.js?<?= fuppi_version() ?>"></script>
    <link rel="stylesheet" type="text/css" href="/assets/css/fuppi.css?<?= fuppi_version() ?>">
    <script src="/assets/js/fuppi.js?<?= fuppi_version() ?>"></script>
</head>

<body>
    <header class="ui grid padded page-header middle aligned">
        <div class="three wide column left aligned clickable" data-url="/">
            <img src="/assets/images/logo/phuppi/phuppi-logo-horizontal-slogan.svg" width="280" height="122" />
        </div>
        <div class="thirteen wide column last bottom aligned right floated center aligned ui item horizontal-menu right aligned">
            <?php if ($user->hasPermission(\Fuppi\UserPermission::USERS_LIST)) { ?>
                <a class="item ui button" href="/users"><i class="user icon"></i> User Management</a>
            <?php } ?>
            <?php if ($user->user_id) { ?>
                <a class="item ui button" href="/logout.php"><i class="icon user"></i>Log Out</a>
            <?php } ?>
        </div>
    </header>
    <?php
    if ($errorMessages = fuppi_get_error_messages()) {
        fuppi_component('messages', ['messages' => $errorMessages, 'type' => 'error']);
    } ?>
    <?php if ($successMessages = fuppi_get_success_messages()) {
        fuppi_component('messages', ['messages' => $successMessages, 'type' => 'success']);
    } ?>
    <div class="ui container content page-content"><?= $content ?></div>
    <footer class="page-footer">
        <div class="ui container two column grid">
            <span class="column">&copy; <?= date('Y') ?> <a href="http://www.phuppi.com">Phuppi</a>, a "File-Uppie" thing.</span>
            <span class="column right aligned">v<?= fuppi_version() ?></span>
        </div>
    </footer>
</body>

</html>