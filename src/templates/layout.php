<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuppi</title>
    <link rel="stylesheet" type="text/css" href="/assets/semantic/dist/semantic.min.css">
    <script src="/assets/jquery/jquery-3.1.1.min.js"></script>
    <script src="/assets/semantic/dist/semantic.min.js"></script>
    <link rel="stylesheet" type="text/css" href="/assets/css/fuppi.css?<?= fuppi_version() ?>">
    <script src="/assets/js/fuppi.js?<?= fuppi_version() ?>"></script>
</head>

<body>
    <header class="ui grid padded page-header middle aligned">
        <div class="three wide column left aligned clickable" data-url="/">
            <img src="/assets/images/fuppi-logo-horizontal.svg" width="280" height="122" />
        </div>
        <div class="thirteen wide column last bottom aligned right floated center aligned ui item horizontal-menu right aligned">
            <!-- <a class="item ui button">Editorials</a>
        <a class="item ui button">Reviews</a>
        <a class="item ui button">Upcoming Events</a> -->
            <?php if ($user->user_id) { ?>
                <a class="item ui button" href="logout.php"><i class="icon user"></i>Log Out</a>
            <?php } ?>
        </div>
    </header>
    <div class="ui container content page-content"><?= $content ?></div>
    <footer class="page-footer">
        <div class="ui container two column grid">
            <span class="column">&copy; <?= date('Y') ?> Fuppi, a "File-Uppie" thing.</span>
            <span class="column right aligned">v<?= fuppi_version() ?></span>
        </div>
    </footer>
</body>

</html>