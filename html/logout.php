<?php

require('fuppi.php');

use Fuppi\UserPermission;

$errors = [];

if ($user->user_id > 0) {
    logout();
}

redirect($_GET['redirectAfterLogin'] ?? '/');
