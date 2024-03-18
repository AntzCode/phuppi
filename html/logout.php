<?php

require('fuppi.php');

use Fuppi\UserPermission;

$errors = [];

if ($user->user_id > 0) {
    if ($user->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
        // @todo: this should be moved to a cron job or service task
        fuppi_gc();
    }
    logout();
}

redirect($_GET['redirectAfterLogin'] ?? '/');
