<?php

require('../src/fuppi.php');

$errors = [];

if ($user->user_id > 0) {
    logout();
}

redirect($_GET['redirectAfterLogin'] ?? '/');

