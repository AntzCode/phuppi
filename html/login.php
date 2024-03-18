<?php

use Fuppi\User;
use Fuppi\Voucher;

require('fuppi.php');

$errors = [];

if (!empty($_POST)) {

    switch ($_POST['_action']) {

        case 'userLogin':

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
            break;

        case 'voucherLogin':
            $voucherCode = trim($_POST['voucher']);

            if (empty($voucherCode)) {
                $errors['voucher'] = ['Voucher cannot be empty'];
            } else {
                if ($voucher = Voucher::findByVoucherCode($voucherCode)) {
                    if (!is_null($voucher->expires_at) && strtotime($voucher->expires_at) < time()) {
                        $errors['voucher'] = ['That voucher has expired'];
                        $_POST['voucher'] = '';
                    } else {
                        if (is_null($voucher->redeemed_at)) {
                            $voucher->redeemed_at = date('Y-m-d H:i:s');
                            if ($voucher->valid_for > 0) {
                                $voucher->expires_at = date('Y-m-d H:i:s', time() + $voucher->valid_for);
                            }
                            $voucher->save();
                        }
                        $user->setData($voucher->getUser()->getData());
                        if (!is_null($voucher->getData()['expires_at'])) {
                            $user->session_expires_at = ''.$voucher->expires_at;
                            $user->save();
                        }
                        $app->setVoucher($voucher);
                        redirect($_GET['redirectAfterLogin'] ?? '/');
                    }
                } else {
                    $errors['voucher'] = ['Invalid voucher'];
                }
            }

            break;
    }

    if (!empty($errors)) {
        fuppi_add_error_message($errors);
    }
}

?>
<div class="content">

    <div class="ui top attached tabular menu">
        <a class="clickable item label <?= (($_POST['_action'] ?? 'userLogin') === 'userLogin' ? 'active' : '') ?>" data-tab="userLogin"><i class="user icon"></i> <label for="username"> Username/Password</a>
        <a class="clickable item label <?= (($_POST['_action'] ?? 'userLogin') === 'voucherLogin' ? 'active' : '') ?>" data-tab="voucherLogin"><i class="ticket icon"></i> <label for="voucher"> Voucher</a>
    </div>
    <div class="ui bottom attached tab segment <?= (($_POST['_action'] ?? 'userLogin') === 'userLogin' ? 'active' : '') ?>" data-tab="userLogin">
        <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
            <input type="hidden" name="_action" value="userLogin" />
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
    <div class="ui bottom attached tab segment <?= (($_POST['_action'] ?? 'userLogin') === 'voucherLogin' ? 'active' : '') ?>" data-tab="voucherLogin">

        <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
            <input type="hidden" name="_action" value="voucherLogin" />
            <div class="field <?= (!empty($errors['username'] ?? []) ? 'error' : '') ?>">
                <label for="username">Voucher: </label>
                <input id="voucher" type="text" name="voucher" value="<?= $_POST['voucher'] ?? '' ?>" />
            </div>

            <button class="ui right labeled icon green button" type="submit"><i class="user icon left"></i> Log In</button>

        </form>

    </div>



</div>