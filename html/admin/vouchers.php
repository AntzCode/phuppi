<?php

use Fuppi\User;
use Fuppi\UserPermission;
use Fuppi\Voucher;

require('../fuppi.php');

if (!$user->hasPermission(UserPermission::USERS_PUT)) {
    // not allowed to access the users list
    fuppi_add_error_message('Not permitted to create users');
    redirect('/');
}

$validForOptions = [
    300 => "5 mins", 600 => "10 mins", 1800 => "30 mins", 3600 => "1 hr", 7200 => "2 hrs", 14400 => "4 hrs", 43200 => "12 hrs", 86400 => "1 day", 259200 => "3 days", 604800 => "1 wk", 2678400 => "1 mth", 7884000 => "3 mths", 15768000 => "6 mths", 31536000 => "1 yr", 0 => "Permanent"
];

if (!empty($_POST)) {
    switch ($_POST['_action'] ?? '') {
        case 'createVoucher':
            if ($user = User::getOne($_POST['user_id'])) {
                if (empty(trim($_POST['voucher_code']))) {
                    fuppi_add_error_message('Voucher code cannot be empty');
                } else {
                    $voucherCode = trim($_POST['voucher_code']);
                    if (strlen($voucherCode) < 6) {
                        fuppi_add_error_message('Voucher code is too short: minimum 6 characters long');
                    } else if (strlen($voucherCode) > 255) {
                        fuppi_add_error_message('Voucher code is too long: max 255 characters long');
                    } else if ($voucher = Voucher::findByVoucherCode($voucherCode)) {
                        fuppi_add_error_message('There is already a voucher with that voucher code');
                    } else if (!array_key_exists(intval($_POST['valid_for']), $validForOptions)) {
                        fuppi_add_error_message('Valid for is outside the allowed range');
                    } else {
                        try {
                            $voucher = new Voucher();
                            $voucher->voucher_code = $voucherCode;
                            $voucher->user_id = $user->user_id;
                            $voucher->created_at = date('Y-m-d H:i:s');
                            $voucher->valid_for = intval($_POST['valid_for']);

                            $voucher->save();
                            fuppi_add_success_message('Voucher created ok!');
                            redirect($_SERVER['REQUEST_URI']);
                        } catch (Exception $e) {
                            fuppi_add_error_message($e->getMessage());
                        }
                    }
                }
            } else {
                fuppi_add_error_message('Invalid user id');
            }
            break;
        case 'deleteVoucher':

            if (Voucher::deleteOne($_POST['voucher_id'])) {
                fuppi_add_success_message('Voucher deleted ok!');
            } else {
                fuppi_add_error_message('Could not delete the voucher');
            }

            break;
    }
}


$allUsers = User::getAll();
$allVouchers = Voucher::getAll();

?>
<div class="ui segment">
    <div class="ui large breadcrumb">
        <a class="section" href="/">Home</a>
        <i class="right chevron icon divider"></i>
        <a class="section">Administration</a>
        <i class="right chevron icon divider"></i>
        <div class="active section">Voucher Management</div>
    </div>
</div>

<h2 class="header"><i class="ticket icon"></i> Voucher Management</h2>

<div class="ui segment">

    <div class="ui top attached label"><i class="ticket icon"></i> <label for="voucher_code">Create a New Voucher</label></div>

    <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">

        <input type="hidden" name="_action" value="createVoucher" />

        <div class="field <?= (!empty($errors['voucher_code'] ?? []) ? 'error' : '') ?>">
            <label for="voucher_code">Voucher Code: </label>
            <input id="voucher_code" type="text" name="voucher_code" value="<?= $_POST['voucher_code'] ?? '' ?>" />
        </div>

        <div class="field <?= (!empty($errors['user_id'] ?? []) ? 'error' : '') ?>">
            <label for="user_id">User: </label>
            <select name="user_id">
                <?php foreach ($allUsers as $user) {
                    echo '<option value="' . $user->user_id . '"' . (($_POST['user_id'] ?? 0) === $user->user_id ? 'selected="selected"' : '') . '>' . $user->username . '</option>';
                } ?>
            </select>
        </div>

        <div class="field <?= (!empty($errors['valid_for'] ?? []) ? 'error' : '') ?>">
            <label for="validForSlider">Valid For: </label>
            <select name="valid_for" id="validForDropdown" style="display: none">
                <?php foreach ($validForOptions as $value => $title) {
                    echo '<option value="' . $value . '"' . (intval($_POST['valid_for'] ?? 0) === $value ? 'selected="selected"' : '') . '>' . $title . '</option>';
                } ?>
            </select>

            <div class="ui labeled ticked slider attached" id="validForSlider"></div>

            <script>
                $('#validForSlider')
                    .slider({
                        min: 0,
                        max: <?= count($validForOptions) - 1 ?>,
                        start: <?= (array_search($_POST['valid_for'] ?? 0, array_keys($validForOptions))) ?>,
                        autoAdjustLabels: true,
                        onChange: (v) => {
                            console.log('It changes to ' + v);
                            document.getElementById('validForDropdown').selectedIndex = v;
                        },
                        interpretLabel: function(value) {
                            let _labels = JSON.parse('<?= json_encode(array_values($validForOptions)) ?>');
                            return _labels[value];
                        }
                    });
            </script>

        </div>

        <div class="ui container right aligned">
            <button class="ui green right labeled icon button" type="submit"><i class="plus icon left"></i> Create</button>
        </div>

    </form>

</div>

<div class="ui segment">

    <div class="ui top attached label">
        <i class="user icon"></i> Existing Vouchers</h2>
    </div>

    <?php if (empty($allVouchers)) { ?>

        <div class="ui content">
            <p>- Empty -</p>
        </div>

    <?php } else { ?>

        <div class="ui divided items">

            <?php foreach ($allVouchers as $voucherIndex => $voucher) { ?>

                <div class="ui item <?= ((!is_null($voucher->expires_at) && strtotime($voucher->expires_at) < time()) ? 'disabled' : '') ?>" style="position: relative; pointer-events: inherit;">

                    <?= ((!is_null($voucher->expires_at) && strtotime($voucher->expires_at) < time())  ? '<label class="ui inverted red left corner label"><i class="info circle icon inverted white" title="Expired at ' . $voucher->expires_at . '"></i></label>' : '') ?>

                    <form id="deleteVoucherForm<?= $voucherIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                        <input name="_method" type="hidden" value="delete" />
                        <input name="_action" type="hidden" value="deleteVoucher" />
                        <input type="hidden" name="voucher_id" value="<?= $voucher->voucher_id ?>" />
                    </form>

                    <button class="red ui top right attached round label raised clickable-confirm" style="z-index: 1;" data-confirm="Are you sure you want to delete this voucher?" data-action="(e) => document.getElementById('deleteVoucherForm<?= $voucherIndex ?>').submit()">
                        <i class="trash icon"></i> Delete Voucher
                    </button>

                    <div class="content">

                        <h2 class="header ui label"><?= $voucher->voucher_code ?> <i class="clickable copy icon copy-to-clipboard" data-content="<?= $voucher->voucher_code ?>"></i></h2>

                        <div class="description">
                            <?= empty($voucher->notes) ? '' : '<p>' . $voucher->notes . '</p>' ?>
                            <p>User: <?= $voucher->getUser()->username ?></p>
                            <p>Created at <?= $voucher->created_at ?></p>
                            <?= (!is_null($voucher->redeemed_at) ? '<p>Redeemed At: ' . $voucher->redeemed_at . '</p>' : '') ?>
                            <?= (!is_null($voucher->expires_at) ? '<p>Expires At: ' . $voucher->expires_at . '</p>' : '') ?>
                            <?= (!is_null($voucher->valid_for) ? '<p>Valid For: ' . $validForOptions[$voucher->valid_for] . '</p>' : '') ?>

                        </div>
                    </div>

                </div>

            <?php } ?>

        </div>

    <?php } ?>

</div>