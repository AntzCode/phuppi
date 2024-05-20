<?php

use Fuppi\Note;
use Fuppi\User;
use Fuppi\UserPermission;
use Fuppi\Voucher;
use Fuppi\VoucherPermission;

require('fuppi.php');

$errors = [];

if ($sharedNote = Note::getOne((int) $_GET['id'] ?? 0)) {

    $isValidToken = false;

    if (isset($_GET['token'])) {
        $token = $_GET['token'];
        if ($sharedNote->isValidToken($token)) {
            $isValidToken = true;
        } else {
            fuppi_add_error_message('Invalid token');
        }
    }

    $isMyFile = $sharedNote->user_id === $user->user_id;

    if ($voucher = $app->getVoucher()) {
        if ($sharedNote->voucher_id !== $voucher->voucher_id) {
            if (!$voucher->hasPermission(VoucherPermission::NOTES_LIST_ALL)) {
                // not allowed to read a file that was not uploaded with the voucher they are using
                $isMyFile = false;
            }
        }
    }

    $canReadUsers = $user->hasPermission(UserPermission::USERS_READ);
    $canReadNotes = $user->hasPermission(UserPermission::NOTES_READ);

    if ($isValidToken || (($isMyFile || $canReadUsers) && $canReadNotes)) {

?>
        <div class="content">
            <div class="ui">
                <h2><?= $sharedNote->filename ?></h2>
                <p>
                    <small>
                        <i class="user icon"></i>Author: <?= User::getUsername($sharedNote->user_id) ?>
                        <?php if ($sharedNote->voucher_id) { ?>
                            (<?= Voucher::getVoucherCode($sharedNote->voucher_id) ?>)
                        <?php } ?>
                    </small>
                    <br />
                    <?php if ($sharedNote->updated_at) { ?>
                        <small><i class="clock icon"></i>Updated at: <?= $sharedNote->updated_at ?></small>
                    <?php } else { ?>
                        <small><i class="clock icon"></i>Created at: <?= $sharedNote->created_at ?></small>
                    <?php } ?>
                    <br />
                    <small><i class="clock icon"></i>Expires at: <?= $sharedNote->getTokenExpiresAt($token) ?> (<?= human_readable_time_remaining(strtotime($sharedNote->getTokenExpiresAt($token))) ?>)</small>
                </p>
            </div>
            <div class="ui segment">
                <?= $sharedNote->content ?>
            </div>
        </div>
<?php

    } else {
        fuppi_add_error_message('Not authorized');
    }
} else {
    fuppi_add_error_message('Cannot find that file');
}
