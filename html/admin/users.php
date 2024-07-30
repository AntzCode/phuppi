<?php

use Fuppi\User;
use Fuppi\UserPermission;

require('../fuppi.php');

if (!$user->hasPermission(UserPermission::USERS_LIST)) {
    // not allowed to access the users list
    fuppi_add_error_message('Not permitted to list users');
    redirect('/');
}

$animalAvatars = ["bear", "beaver", "cat", "cheetah", "chicken", "cow", "crocodile", "doe", "dog", "duck", "elephant", "fox", "frog", "giraffe", "goat", "gorilla", "hedgehog", "hippo", "horse", "koala", "lemur", "lion", "llama", "monkey", "mouse", "otter", "owl", "panda", "penguin", "pig", "rabbit", "racoon", "rhino", "sheep", "sloth", "snake", "tiger", "wolf", "yak", "zebra"];

$permissionTitles = [
    UserPermission::UPLOADEDFILES_DELETE => 'Delete Files',
    UserPermission::UPLOADEDFILES_PUT => 'Upload Files',
    UserPermission::UPLOADEDFILES_LIST => 'List Files',
    UserPermission::UPLOADEDFILES_READ => 'Read Files',
    UserPermission::USERS_LIST => 'List Users',
    UserPermission::USERS_READ => 'Read Users',
    UserPermission::USERS_PUT => 'Write Users',
    UserPermission::USERS_DELETE => 'Delete Users',
    UserPermission::IS_ADMINISTRATOR => 'Is Administrator'
];

$selectedPermissions = [
    'UPLOADEDFILES_PUT',
    'UPLOADEDFILES_LIST',
    'UPLOADEDFILES_READ',
    'UPLOADEDFILES_DELETE',
];

if (!empty($_POST)) {
    switch ($_POST['_action']) {
        case 'deleteUser':
            if ($user->hasPermission(UserPermission::USERS_DELETE)) {
                $userId = $_POST['userId'] ?? 0;
                $existingUser = User::getOne($userId);
                if ($existingUser->user_id > 0 && $existingUser->user_id !== $user->user_id) {
                    if ($existingUser->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
                        // trying to delete an administrator, which is an action that only administrators are allowed to perform
                        if (!$user->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
                            User::disableUser($user, 'while not being an Administrator, has attempted to delete the Administrator "' . $existingUser->username . ' (' . $existingUser->user_id . ')"');
                            logout();
                            fuppi_add_error_message('You have performed an illegal action, your account has been disabled');
                            redirect('/');
                        }
                    }
                    User::deleteOne($existingUser->user_id);
                    fuppi_add_success_message($existingUser->username . ' was deleted');
                }
                redirect($_SERVER['REQUEST_URI']);
            } else {
                fuppi_add_error_message('Not permitted to delete that user');
            }
            break;

        case 'deleteUserPermission':
            if ($user->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
                $userId = $_POST['userId'] ?? 0;
                $existingUser = User::getOne($userId);
                if ($existingUser->user_id > 0 && $existingUser->user_id !== $user->user_id) {
                    $existingUser->deletePermission($_POST['permissionName']);
                    fuppi_add_success_message('User permission was deleted');
                }
                redirect($_SERVER['REQUEST_URI']);
            } else {
                fuppi_add_error_message('Not permitted to change user permissions');
            }
            break;

        case 'addUserPermission':
            if ($user->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
                $userId = $_POST['userId'] ?? 0;
                $existingUser = User::getOne($userId);
                if ($existingUser->user_id > 0 && $existingUser->user_id !== $user->user_id) {
                    $permissionName = $_POST['permissionName'];
                    if (!$existingUser->hasPermission($permissionName)) {
                        if (array_key_exists($permissionName, $permissionTitles)) {
                            $existingUser->addPermission($permissionName);
                        }
                    }
                    fuppi_add_success_message('User permission was added: ' . $userId . '/' . $permissionName);
                }
                redirect($_SERVER['REQUEST_URI']);
            } else {
                fuppi_add_error_message('Not permitted to change user permissions');
            }
            break;

        case 'createUser':

            if ($user->hasPermission(UserPermission::USERS_PUT)) {
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';

                if (User::filterUsername($username) !== $username) {
                    $errors['username'] = array_merge($errors['username'] ?? [], ['Username contains invalid characters']);
                } else if (strlen($username) < 3) {
                    $errors['username'] = array_merge($errors['username'] ?? [], ['Username is too short']);
                } else if (strlen($username) > 80) {
                    $errors['username'] = array_merge($errors['username'] ?? [], ['Username is too long']);
                } else if ($newUser = User::findByUsername($username)) {
                    $errors['username'] = array_merge($errors['username'] ?? [], ['Username is already registered']);
                }

                if (empty(trim($password))) {
                    $errors['password'] = array_merge($errors['password'] ?? [], ['Password cannot be empty']);
                } else if (strlen($password) < 6) {
                    $errors['password'] = array_merge($errors['password'] ?? [], ['Password is too short']);
                } else if (strlen($password) > 80) {
                    $errors['password'] = array_merge($errors['password'] ?? [], ['Password is too long']);
                }

                if (empty($errors)) {
                    // ok to process
                    $newUser = new User();
                    $newUser->setData([
                        'username' => $username,
                        'password' => password_hash($password, PASSWORD_BCRYPT),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $newUser->save();

                    foreach ($_POST['permissions'] as $permission) {
                        switch ($permission) {
                            case 'IS_ADMINISTRATOR':
                                if ($user->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
                                    // only Administrators can assign Administrator permission
                                    $newUser->addPermission(UserPermission::IS_ADMINISTRATOR);
                                }
                                break;

                            case 'USERS_WRITE_READ':
                                $newUser->addPermission(UserPermission::USERS_PUT);
                                $newUser->addPermission(UserPermission::USERS_READ);
                                $newUser->addPermission(UserPermission::USERS_LIST);
                                break;

                            case 'USERS_READ':
                                $newUser->addPermission(UserPermission::USERS_READ);
                                $newUser->addPermission(UserPermission::USERS_LIST);
                                break;

                            case 'UPLOADEDFILES_PUT':
                                $newUser->addPermission(UserPermission::UPLOADEDFILES_PUT);
                                break;

                            case 'UPLOADEDFILES_LIST':
                                $newUser->addPermission(UserPermission::UPLOADEDFILES_LIST);
                                break;

                            case 'UPLOADEDFILES_READ':
                                $newUser->addPermission(UserPermission::UPLOADEDFILES_READ);
                                break;

                            case 'UPLOADEDFILES_DELETE':
                                $newUser->addPermission(UserPermission::UPLOADEDFILES_DELETE);
                                break;
                        }
                    }

                    fuppi_add_success_message('Created user ' . $newUser->username . ' with id ' . $newUser->user_id);
                    redirect($_SERVER['REQUEST_URI']);
                } else {
                    $selectedPermissions = $_POST['permissions'];
                    fuppi_add_error_message($errors);
                }
            } else {
                fuppi_add_error_message(['Not permitted to add users']);
            }

            break;
    }
}

$allUsers = User::getAll();
$allUsersPermissions = [];
$allUsersNonPermissions = [];

foreach ($allUsers as $existingUser) {
    if ($existingUser->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
        $allUsersPermissions[$existingUser->user_id] = [UserPermission::IS_ADMINISTRATOR => true];
        $allUsersNonPermissions[$existingUser->user_id] = [];
    } else {
        $allUsersPermissions[$existingUser->user_id] = [
            UserPermission::UPLOADEDFILES_DELETE => $existingUser->hasPermission(UserPermission::UPLOADEDFILES_DELETE),
            UserPermission::UPLOADEDFILES_PUT => $existingUser->hasPermission(UserPermission::UPLOADEDFILES_PUT),
            UserPermission::UPLOADEDFILES_LIST => $existingUser->hasPermission(UserPermission::UPLOADEDFILES_LIST),
            UserPermission::UPLOADEDFILES_READ => $existingUser->hasPermission(UserPermission::UPLOADEDFILES_READ),
            UserPermission::USERS_LIST => $existingUser->hasPermission(UserPermission::USERS_LIST),
            UserPermission::USERS_READ => $existingUser->hasPermission(UserPermission::USERS_READ),
            UserPermission::USERS_PUT => $existingUser->hasPermission(UserPermission::USERS_PUT),
            UserPermission::USERS_DELETE => $existingUser->hasPermission(UserPermission::USERS_DELETE),
        ];

        $allUsersNonPermissions[$existingUser->user_id] = [
            UserPermission::UPLOADEDFILES_DELETE => !$existingUser->hasPermission(UserPermission::UPLOADEDFILES_DELETE),
            UserPermission::UPLOADEDFILES_PUT => !$existingUser->hasPermission(UserPermission::UPLOADEDFILES_PUT),
            UserPermission::UPLOADEDFILES_LIST => !$existingUser->hasPermission(UserPermission::UPLOADEDFILES_LIST),
            UserPermission::UPLOADEDFILES_READ => !$existingUser->hasPermission(UserPermission::UPLOADEDFILES_READ),
            UserPermission::USERS_LIST => !$existingUser->hasPermission(UserPermission::USERS_LIST),
            UserPermission::USERS_READ => !$existingUser->hasPermission(UserPermission::USERS_READ),
            UserPermission::USERS_PUT => !$existingUser->hasPermission(UserPermission::USERS_PUT),
            UserPermission::USERS_DELETE => !$existingUser->hasPermission(UserPermission::USERS_DELETE),
            UserPermission::IS_ADMINISTRATOR => !$existingUser->hasPermission(UserPermission::IS_ADMINISTRATOR)
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
        <div class="active section">User Management</div>
    </div>
</div>

<?php if ($user->hasPermission(UserPermission::USERS_PUT)) { ?>
    <div class="ui segment">

        <h2 class="ui header"><i class="user icon"></i> Create a New User</h2>

        <form class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">

            <input type="hidden" name="_action" value="createUser" />

                <div class="field <?= (!empty($errors['username'] ?? []) ? 'error' : '') ?>">
                    <label for="username">Username: </label>
                    <input id="username" type="text" name="username" value="<?= $_POST['username'] ?? '' ?>" />
                </div>
                <div class="field <?= (!empty($errors['password'] ?? []) ? 'error' : '') ?>">
                    <label for="password">Password: </label>
                    <input id="password" type="password" name="password" value="<?= $_POST['password'] ?? '' ?>" />
                </div>
                <div class="field <?= (!empty($errors['permissions'] ?? []) ? 'error' : '') ?>">
                    <label for="permissions">Permissions: </label>
                    <select class="ui fluid dropdown" id="permissions" name="permissions[]" multiple="">
                        <?php if ($user->hasPermission(UserPermission::IS_ADMINISTRATOR)) { ?>
                            <option <?= (in_array('IS_ADMINISTRATOR', $selectedPermissions) ? 'selected="selected"' : '') ?> value="IS_ADMINISTRATOR">Administrator</option>
                        <?php } ?>
                        <option <?= (in_array('USERS_WRITE_READ', $selectedPermissions) ? 'selected="selected"' : '') ?> value="USERS_WRITE_READ">Read & Write Users</option>
                        <option <?= (in_array('USERS_READ', $selectedPermissions) ? 'selected="selected"' : '') ?> value="USERS_READ">Read-Only Users</option>
                        <option <?= (in_array('UPLOADEDFILES_PUT', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_PUT">Upload Files</option>
                        <option <?= (in_array('UPLOADEDFILES_LIST', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_LIST">List Files</option>
                        <option <?= (in_array('UPLOADEDFILES_READ', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_READ">Download Files</option>
                        <option <?= (in_array('UPLOADEDFILES_DELETE', $selectedPermissions) ? 'selected="selected"' : '') ?> value="UPLOADEDFILES_DELETE">Delete Files</option>
                    </select>
                </div>

            <div class="ui segment center aligned">
                <button class="ui primary right labeled icon button" type="submit"><i class="plus icon left"></i> Create</button>
            </div>

        </form>

    </div>

<?php } ?>

<div class="ui segment">

    <div class="ui top attached label">
        <i class="user icon"></i> Existing Users</h2>
    </div>

    <?php if (empty($allUsers)) { ?>

        <div class="ui content">
            <p>- Empty -</p>
        </div>

    <?php } else { ?>

        <div class="ui divided items">

            <?php foreach ($allUsers as $existingUserIndex => $existingUser) { ?>

                <div class="ui item" style="position: relative">

                    <?php if ($user->hasPermission(UserPermission::USERS_DELETE) && $existingUser->user_id !== $user->user_id) { ?>
                        <form id="deleteUserForm<?= $existingUserIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                            <input name="_method" type="hidden" value="delete" />
                            <input name="_action" type="hidden" value="deleteUser" />
                            <input type="hidden" name="userId" value="<?= $existingUser->user_id ?>" />
                        </form>
                        <?php if ($user->hasPermission(UserPermission::IS_ADMINISTRATOR)) { ?>
                            <form id="deleteUserPermissionForm<?= $existingUserIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                                <input name="_method" type="hidden" value="delete" />
                                <input name="_action" type="hidden" value="deleteUserPermission" />
                                <input type="hidden" name="userId" value="<?= $existingUser->user_id ?>" />
                                <input type="hidden" name="permissionName" value="" />
                            </form>
                            <form id="addUserPermissionForm<?= $existingUserIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                                <input name="_method" type="hidden" value="delete" />
                                <input name="_action" type="hidden" value="addUserPermission" />
                                <input type="hidden" name="userId" value="<?= $existingUser->user_id ?>" />
                                <input type="hidden" name="permissionName" value="" />
                            </form>
                        <?php } ?>

                    <?php } ?>

                    <div class="ui tiny image raised">
                        <img src="/assets/images/animal-avatars/<?= $animalAvatars[array_rand($animalAvatars)] ?>.png" />
                    </div>

                    <div class="content">

                        <h2 class="header ui label" style="width: 100%">
                            <span style="display: flex; width: 100%; flex-direction: row;">
                                <span style="flex: 1; word-break: break-all;">
                                    <?= $existingUser->username ?>
                                </span>
                                <?php if ($user->hasPermission(UserPermission::USERS_DELETE) && $existingUser->user_id !== $user->user_id) { ?>
                                    <span style="flex: 0">
                                        <i class="red trash icon clickable-confirm"
                                            title="Delete User"
                                            data-confirm="Are you sure you want to delete this user?" data-action="(e) => document.getElementById('deleteUserForm<?= $existingUserIndex ?>').submit()"></i>
                                    </span>
                                <?php } ?>

                        </h2>

                        <div class="description">
                            <div class="ui grid container">
                                <div class="ten wide column">
                                    <p>Created at <?= $existingUser->created_at ?></p>
                                </div>
                                <div class="six wide column right aligned">
                                    <p>
                                        <?= human_readable_bytes($existingUser->getSumUploadedFilesSize()) ?><br />
                                        <?= count($existingUser->getUploadedFiles()) ?> uploaded files<br />
                                        <?php if ($user->hasPermission(UserPermission::USERS_READ)) { ?>
                                            <a href="/index.php?userId=<?= $existingUser->user_id ?>">view files &raquo;</a>
                                        <?php } ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="extra floated left">
                            <?php foreach ($allUsersPermissions[$existingUser->user_id] as $permission => $has) { ?>
                                <?php if (!$has) {
                                    continue;
                                } ?>
                                <div class="green ui label" title="<?= $permissionTitles[$permission] ?>">
                                    <?= $permissionTitles[$permission] ?>
                                    <?php if ($user->hasPermission(UserPermission::IS_ADMINISTRATOR) && $user->user_id !== $existingUser->user_id) { ?>
                                        <span>
                                            <i title="Remove this Permission" class="ui right remove icon attached clickable-confirm" data-confirm="Are you sure you want to remove this permission?" data-action="(e) => {let form = document.getElementById('deleteUserPermissionForm<?= $existingUserIndex ?>'); form.permissionName.value='<?= $permission ?>'; form.submit()}"></i>
                                        </span>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>
                        <div>
                            <?php foreach ($allUsersNonPermissions[$existingUser->user_id] as $nonPermission => $has) { ?>
                                <?php if (!$has) {
                                    continue;
                                } ?>
                                <div class="gray ui icon label" title="<?= $permissionTitles[$nonPermission] ?>">
                                    <?= $permissionTitles[$nonPermission] ?>
                                    <?php if ($user->hasPermission(UserPermission::IS_ADMINISTRATOR)) { ?>
                                        <i title="Add this Permission" class="add icon clickable-confirm" data-confirm="Are you sure you want to add this permission?" data-action="(e) => {let form = document.getElementById('addUserPermissionForm<?= $existingUserIndex ?>'); form.permissionName.value='<?= $nonPermission ?>'; form.submit()}"></i>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>

                    </div>

                </div>

            <?php } ?>

        </div>

    <?php } ?>

</div>