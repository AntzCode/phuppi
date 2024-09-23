<?php

use Fuppi\User;
use Fuppi\Voucher;

require('fuppi.php');

$errors = [];

if (!empty($_GET['voucher']) && empty($_POST)) {
    ?>
        <form id="voucherLoginForm" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>" >
            <input type="hidden" name="_action" value="voucherLogin" />
            <input type="hidden" name="voucher" value="<?= $_GET['voucher'] ?>" />
            <input type="submit" value="Click to log in" />
            <p id="message">Logging in...</p>
        </form>
        <script type="text/javascript">
            document.querySelector('#voucherLoginForm input[type=submit]').style.display = 'none';
            setTimeout(() => {
                document.querySelector('#message').style.display = 'none';
                document.querySelector('#voucherLoginForm input[type=submit]').style.display = 'inline';
            }, 6000);
            setTimeout(() => document.getElementById('voucherLoginForm').submit(), 1500);
        </script>
    <?php
    return;
}

if (!empty($_POST)) {
    switch ($_POST['_action']) {
        case 'userLogin':

            if ($authenticatingUser = User::findByUsername($_POST['username'] ?? '')) {
                if (!empty("{$authenticatingUser->password}") && password_verify($_POST['password'], $authenticatingUser->password)) {
                    if (!is_null($authenticatingUser->disabled_at)) {
                        fuppi_add_error_message('Your account has been disabled.');
                    } else {
                        $user->setData($authenticatingUser->getData());
                        if (($_POST['persist'] ?? 0) > 0) {
                            // set a cookie to keep them logged in
                            $authenticatingUser->setPersistentCookie(session_id(), time() + $config->session_persist_cookie_lifetime, $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR']);
                            $_COOKIE[$config->session_persist_cookie_name] = session_id();
                            setcookie($config->session_persist_cookie_name, session_id(), time() + $config->session_persist_cookie_lifetime, $config->session_persist_cookie_path, $config->session_persist_cookie_domain);
                        }
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
                            $user->session_expires_at = '' . $voucher->expires_at;
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

            <div class="field inline <?= (!empty($errors['persist'] ?? []) ? 'error' : '') ?>">
                <label for="persist">Stay logged in </label>
                <input id="persist" type="checkbox" name="persist" value="1" <?= ((($_POST['persist'] ?? 0) > 0) ? 'checked="checked"' : '') ?> />
            </div>

            <div class="ui vertical segment">
                <button class="ui right labeled icon green button" type="submit"><i class="user icon left"></i> Log In</button>
            </div>
            
        </form>

    </div>
    <div class="ui bottom attached tab segment <?= (($_POST['_action'] ?? 'userLogin') === 'voucherLogin' ? 'active' : '') ?>" data-tab="voucherLogin">

        <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
            <input type="hidden" name="_action" value="voucherLogin" />
            <div class="field <?= (!empty($errors['voucher'] ?? []) ? 'error' : '') ?>">
                <label for="voucher">Voucher: </label>
                <input id="voucher" type="text" name="voucher" value="<?= $_POST['voucher'] ?? '' ?>" />
            </div>

            <button class="ui right labeled icon green button" type="submit"><i class="user icon left"></i> Log In</button>

        </form>

    </div>



</div>