<?php

use Fuppi\User;
use Fuppi\UserPermission;
use Fuppi\Voucher;
use Fuppi\VoucherPermission;

require('../fuppi.php');

if (!$user->hasPermission(UserPermission::USERS_PUT)) {
    // not allowed to access the users list
    fuppi_add_error_message('Not permitted to create users');
    redirect('/');
}

$permissionTitles = [
    VoucherPermission::UPLOADEDFILES_DELETE => 'Delete Files',
    VoucherPermission::UPLOADEDFILES_PUT => 'Upload Files',
    VoucherPermission::UPLOADEDFILES_LIST => 'List Files',
    VoucherPermission::UPLOADEDFILES_LIST_ALL => 'List All Files',
    VoucherPermission::UPLOADEDFILES_READ => 'Read Files',
    VoucherPermission::USERS_LIST => 'List Users',
    VoucherPermission::USERS_READ => 'Read Users',
    VoucherPermission::USERS_PUT => 'Write Users',
    VoucherPermission::USERS_DELETE => 'Delete Users',
    VoucherPermission::IS_ADMINISTRATOR => 'Is Administrator'
];

$selectedPermissions = [
    'UPLOADEDFILES_PUT',
    'UPLOADEDFILES_LIST',
    'UPLOADEDFILES_READ',
    'UPLOADEDFILES_DELETE',
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
                    } else if (!array_key_exists(intval($_POST['valid_for']), $config->voucher_valid_for_options)) {
                        fuppi_add_error_message('Valid for is outside the allowed range');
                    } else {
                        try {
                            $voucher = new Voucher();
                            $voucher->voucher_code = $voucherCode;
                            $voucher->user_id = $user->user_id;
                            $voucher->created_at = date('Y-m-d H:i:s');
                            $voucher->valid_for = intval($_POST['valid_for']);

                            $voucher->save();

                            foreach ($_POST['permissions'] as $permission) {
                                switch ($permission) {
                                    case 'IS_ADMINISTRATOR':
                                        $voucher->addPermission(VoucherPermission::IS_ADMINISTRATOR);
                                        break;

                                    case 'USERS_WRITE_READ':
                                        $voucher->addPermission(VoucherPermission::USERS_PUT);
                                        $voucher->addPermission(VoucherPermission::USERS_READ);
                                        $voucher->addPermission(VoucherPermission::USERS_LIST);
                                        break;

                                    case 'USERS_READ':
                                        $voucher->addPermission(VoucherPermission::USERS_READ);
                                        $voucher->addPermission(VoucherPermission::USERS_LIST);
                                        break;

                                    case 'UPLOADEDFILES_PUT':
                                        $voucher->addPermission(VoucherPermission::UPLOADEDFILES_PUT);
                                        break;

                                    case 'UPLOADEDFILES_LIST':
                                        $voucher->addPermission(VoucherPermission::UPLOADEDFILES_LIST);
                                        break;

                                    case 'UPLOADEDFILES_READ':
                                        $voucher->addPermission(VoucherPermission::UPLOADEDFILES_READ);
                                        break;

                                    case 'UPLOADEDFILES_DELETE':
                                        $voucher->addPermission(VoucherPermission::UPLOADEDFILES_DELETE);
                                        break;
                                }
                            }

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
            $selectedPermissions = $_POST['permissions'];
            break;

        case 'deleteVoucherPermission':
            $voucherId = $_POST['voucherId'] ?? 0;
            $voucher = Voucher::getOne($voucherId);
            $voucher->deletePermission($_POST['permissionName']);
            fuppi_add_success_message('Voucher permission was deleted');
            redirect($_SERVER['REQUEST_URI']);
            break;

        case 'addVoucherPermission':
            $voucherId = $_POST['voucherId'] ?? 0;
            $voucher = Voucher::getOne($voucherId);

            $permissionName = $_POST['permissionName'];
            if (!$voucher->hasPermission($permissionName)) {
                if (array_key_exists($permissionName, $permissionTitles)) {
                    $voucher->addPermission($permissionName);
                }
                fuppi_add_success_message('Voucher permission was added: ' . $voucherId . '/' . $permissionName);
                redirect($_SERVER['REQUEST_URI']);
            }
            break;

        case 'deleteVoucher':
            if (Voucher::deleteOne($_POST['voucherId'])) {
                fuppi_add_success_message('Voucher deleted ok!');
            } else {
                fuppi_add_error_message('Could not delete the voucher');
            }
            break;
    }
}


$allUsers = User::getAll();
$allVouchers = Voucher::getAll();
$allVouchersPermissions = [];
$allVouchersNonPermissions = [];

foreach ($allVouchers as $voucher) {
    if ($voucher->hasPermission(VoucherPermission::IS_ADMINISTRATOR)) {
        $allVouchersPermissions[$voucher->voucher_id] = [VoucherPermission::IS_ADMINISTRATOR => true];
        $allVouchersNonPermissions[$voucher->voucher_id] = [];
    } else {
        $allVouchersPermissions[$voucher->voucher_id] = [
            VoucherPermission::UPLOADEDFILES_DELETE => $voucher->hasPermission(VoucherPermission::UPLOADEDFILES_DELETE),
            VoucherPermission::UPLOADEDFILES_PUT => $voucher->hasPermission(VoucherPermission::UPLOADEDFILES_PUT),
            VoucherPermission::UPLOADEDFILES_LIST => $voucher->hasPermission(VoucherPermission::UPLOADEDFILES_LIST),
            VoucherPermission::UPLOADEDFILES_LIST_ALL => $voucher->hasPermission(VoucherPermission::UPLOADEDFILES_LIST_ALL),
            VoucherPermission::UPLOADEDFILES_READ => $voucher->hasPermission(VoucherPermission::UPLOADEDFILES_READ),
            VoucherPermission::USERS_LIST => $voucher->hasPermission(VoucherPermission::USERS_LIST),
            VoucherPermission::USERS_READ => $voucher->hasPermission(VoucherPermission::USERS_READ),
            VoucherPermission::USERS_PUT => $voucher->hasPermission(VoucherPermission::USERS_PUT),
            VoucherPermission::USERS_DELETE => $voucher->hasPermission(VoucherPermission::USERS_DELETE),
        ];

        $allVouchersNonPermissions[$voucher->voucher_id] = [
            VoucherPermission::UPLOADEDFILES_DELETE => !$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_DELETE),
            VoucherPermission::UPLOADEDFILES_PUT => !$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_PUT),
            VoucherPermission::UPLOADEDFILES_LIST => !$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_LIST),
            VoucherPermission::UPLOADEDFILES_LIST_ALL => !$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_LIST_ALL),
            VoucherPermission::UPLOADEDFILES_READ => !$voucher->hasPermission(VoucherPermission::UPLOADEDFILES_READ),
            VoucherPermission::USERS_LIST => !$voucher->hasPermission(VoucherPermission::USERS_LIST),
            VoucherPermission::USERS_READ => !$voucher->hasPermission(VoucherPermission::USERS_READ),
            VoucherPermission::USERS_PUT => !$voucher->hasPermission(VoucherPermission::USERS_PUT),
            VoucherPermission::USERS_DELETE => !$voucher->hasPermission(VoucherPermission::USERS_DELETE),
            VoucherPermission::IS_ADMINISTRATOR => !$voucher->hasPermission(VoucherPermission::IS_ADMINISTRATOR)
        ];
    }
}

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

<form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">

    <input type="hidden" name="_action" value="createVoucher" />

    <div class="ui segment">

        <h3 class="header"><i class="ticket icon"></i> <label for="voucher_code">Create a New Voucher</label></h3>
        
        <div class="ui content">
            <p>
                <span class="ui gray med text">
                    Vouchers are a way to share limited access to phuppi. 
                    You can restrict permissions to only allow certain actions, and you can choose a limited lifespan for the voucher.
                    A user does not need to create an account when they redeem a voucher, because the voucher already creates and reads files on behalf of the chosen account.
                </span>
            </p>
        </div>

        <div class="ui horizontal equal width basic segments stackable">
            
            <div class="ui segment">
                <div class="content">
                    <div class="field <?= (!empty($errors['voucher_code'] ?? []) ? 'error' : '') ?>">
                        <label for="voucher_code">Voucher Code: </label>
                        <input id="voucher_code" type="text" name="voucher_code" value="<?= $_POST['voucher_code'] ?? '' ?>" />
                    </div>
                    <div class="ui up pointing label">
                        <div class="ui center aligned two columns grid">
                            <div class="two wide column"><i class="ui large circle info icon"></i></div>
                            <div class="fourteen wide column left aligned"><span class="ui text">Create a memorable token that isn't too easy to guess. <br />Minimum 6 characters long.</span></div>
                        </div>
                    </div>
                    <div class="field <?= (!empty($errors['user_id'] ?? []) ? 'error' : '') ?>">
                        <label for="user_id">User: </label>
                        <select name="user_id">
                            <?php foreach ($allUsers as $user) {
                                echo '<option value="' . $user->user_id . '"' . (($_POST['user_id'] ?? 0) === $user->user_id ? 'selected="selected"' : '') . '>' . $user->username . '</option>';
                            } ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="ui segment">
                <div class="content">
                    <div class="field <?= (!empty($errors['permissions'] ?? []) ? 'error' : '') ?>">
                        <label for="permissions">Permissions: </label>
                        <select class="ui fluid dropdown" id="permissions" name="permissions[]" multiple="">
                            <option <?= (in_array('IS_ADMINISTRATOR', $selectedPermissions) ? 'selected="selected"' : '') ?> value="IS_ADMINISTRATOR">Administrator</option>
                            <option <?= (in_array('USERS_WRITE_READ', $selectedPermissions) ? 'selected="selected"' : '') ?> value="USERS_WRITE_READ">Read & Write Users</option>
                            <option <?= (in_array('USERS_READ', $selectedPermissions) ? 'selected="selected"' : '') ?> value="USERS_READ">Read-Only Users</option>
                            <option <?= (in_array('UPLOADEDFILES_PUT', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_PUT">Upload Files</option>
                            <option <?= (in_array('UPLOADEDFILES_LIST', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_LIST">List Files</option>
                            <option <?= (in_array('UPLOADEDFILES_LIST_ALL', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_LIST_ALL">List All Files</option>
                            <option <?= (in_array('UPLOADEDFILES_READ', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_READ">Download Files</option>
                            <option <?= (in_array('UPLOADEDFILES_DELETE', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_DELETE">Delete Files</option>
                        </select>
                    </div>    
                </div>
            </div>
        </div>

        <div class="ui basic segment">
            <div class="ui one cards">
                <div class="ui card basic">

                    <div class="ui header small">
                        Voucher Expiration
                    </div>
                    
                    <div class="content">
                        
                        <div class="field <?= (!empty($errors['valid_for'] ?? []) ? 'error' : '') ?>">
                            
                            <select name="valid_for" id="validForDropdown" style="display: none">
                                <?php foreach ($config->voucher_valid_for_options as $value => $title) {
                                    echo '<option value="' . $value . '"' . (intval($_POST['valid_for'] ?? 0) === $value ? 'selected="selected"' : '') . '>' . $title . '</option>';
                                } ?>
                            </select>

                            <div class="ui labeled ticked slider attached" id="validForSlider"></div>

                            <script>
                                $('#validForSlider')
                                    .slider({
                                        min: 0,
                                        max: <?= count($config->voucher_valid_for_options) - 1 ?>,
                                        start: <?= (array_search($_POST['valid_for'] ?? 0, array_keys($config->voucher_valid_for_options))) ?>,
                                        autoAdjustLabels: true,
                                        onChange: (v) => {
                                            document.getElementById('validForDropdown').selectedIndex = v;
                                        },
                                        interpretLabel: function(value) {
                                            let _labels = JSON.parse('<?= json_encode(array_values($config->voucher_valid_for_options)) ?>');
                                            return _labels[value];
                                        }
                                    });
                            </script>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ui segment center aligned">
            <button class="ui primary right labeled icon button" type="submit"><i class="plus icon left"></i> Create</button>
        </div>

    </div>

</form>

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

                    <?= ((!is_null($voucher->expires_at) && strtotime($voucher->expires_at) < time()) ? '<label class="ui inverted red left corner label"><i class="info circle icon inverted white" title="Expired at ' . $voucher->expires_at . '"></i></label>' : '') ?>

                    <form id="deleteVoucherForm<?= $voucherIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                        <input name="_method" type="hidden" value="delete" />
                        <input name="_action" type="hidden" value="deleteVoucher" />
                        <input type="hidden" name="voucherId" value="<?= $voucher->voucher_id ?>" />
                    </form>
                    <form id="deleteVoucherPermissionForm<?= $voucherIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                        <input name="_method" type="hidden" value="delete" />
                        <input name="_action" type="hidden" value="deleteVoucherPermission" />
                        <input type="hidden" name="voucherId" value="<?= $voucher->voucher_id ?>" />
                        <input type="hidden" name="permissionName" value="" />
                    </form>
                    <form id="addVoucherPermissionForm<?= $voucherIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                        <input name="_method" type="hidden" value="delete" />
                        <input name="_action" type="hidden" value="addVoucherPermission" />
                        <input type="hidden" name="voucherId" value="<?= $voucher->voucher_id ?>" />
                        <input type="hidden" name="permissionName" value="" />
                    </form>

                    <div class="content">

                        <h2 class="header ui label"  style="width: 100%;">
                            <span style="width: 100%; display: flex; flex-direction: row;">
                                <span style="flex: 0">
                                    <i class="clickable copy icon copy-to-clipboard"
                                        data-content="<?= $voucher->voucher_code ?>"></i>
                                </span>    
                                
                                <span style="flex: 1; word-break: break-all;"><?= $voucher->voucher_code ?></span> 
                                
                                <span style="flex: 0">
                                    <i class="clickable red round trash icon clickable-confirm" 
                                        title="Delete Voucher"
                                        data-confirm="Are you sure you want to delete this voucher?" 
                                        data-action="(e) => document.getElementById('deleteVoucherForm<?= $voucherIndex ?>').submit()">
                                    </i>
                                </span>
                            </span>
                        </h2>

                        <div class="description">
                            <p class="ui header">
                                <span style="flex: 0">
                                    <i class="clickable copy icon copy-to-clipboard"
                                        data-content="<?= $config->base_url ?>/login.php?voucher=<?= $voucher->voucher_code ?>"></i>
                                </span>
                                <a href="//<?= $config->base_url ?>/login.php?voucher=<?= $voucher->voucher_code ?>"><?= $config->base_url ?>/login.php?voucher=<?= $voucher->voucher_code ?></a>
                            </p>
                            <?= empty($voucher->notes) ? '' : '<p>' . $voucher->notes . '</p>' ?>
                            <p>User: <?= $voucher->getUser()->username ?></p>
                            <p>Created at <?= $voucher->created_at ?></p>
                            <?= (!is_null($voucher->redeemed_at) ? '<p>Redeemed At: ' . $voucher->redeemed_at . '</p>' : '') ?>
                            <?= (!is_null($voucher->expires_at) ? '<p>Expires At: ' . $voucher->expires_at . '</p>' : '') ?>
                            <?= (!is_null($voucher->valid_for) ? '<p>Valid For: ' . $config->voucher_valid_for_options[$voucher->valid_for] . '</p>' : '') ?>
                        </div>

                        <div class="extra">
                            <div>
                                <?php foreach ($allVouchersPermissions[$voucher->voucher_id] as $permission => $has) { ?>
                                    <?php if (!$has) {
                                        continue;
                                    } ?>
                                    <div class="green ui label" title="<?= $permissionTitles[$permission] ?>">
                                        <?= $permissionTitles[$permission] ?>
                                        <span>
                                            <i title="Remove this Permission" class="ui right remove icon attached clickable-confirm" data-confirm="Are you sure you want to remove this permission?" data-action="(e) => {let form = document.getElementById('deleteVoucherPermissionForm<?= $voucherIndex ?>'); form.permissionName.value='<?= $permission ?>'; form.submit()}"></i>
                                        </span>
                                    </div>
                                <?php } ?>
                            </div>
                            <div>
                                <?php foreach ($allVouchersNonPermissions[$voucher->voucher_id] as $nonPermission => $has) { ?>
                                    <?php if (!$has) {
                                        continue;
                                    } ?>
                                    <div class="gray ui icon label" title="<?= $permissionTitles[$nonPermission] ?>">
                                        <?= $permissionTitles[$nonPermission] ?>
                                        <i title="Add this Permission" class="add icon clickable-confirm" data-confirm="Are you sure you want to add this permission?" data-action="(e) => {let form = document.getElementById('addVoucherPermissionForm<?= $voucherIndex ?>'); form.permissionName.value='<?= $nonPermission ?>'; form.submit()}"></i>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php } ?>

        </div>

    <?php } ?>

</div>