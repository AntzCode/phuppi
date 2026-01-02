<?php

namespace Phuppi\Controllers;

use Flight;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;

use Valitron\Validator;

class UserController
{
    public function login()
    {
        if (Flight::request()->method == 'POST') {
            $this->handleLogin();
        } else {
            $this->showLoginForm();
        }
    }

    private function showLoginForm()
    {
        Flight::render('login.latte', [
            'formUrl' => '/login',
            'username' => '',
            'error' => '',
            'voucherCode' => '',
            'errorUsername' => '',
            'errorPassword' => '',
            'errorVoucher' => '',
            'activeTab' => 'login'
        ]);
    }

    private function handleLogin()
    {
        $loginType = Flight::request()->data->login_type ?? 'user';

        if ($loginType === 'voucher') {
            $this->handleVoucherLogin();
        } else {
            $this->handleUserLogin();
        }
    }

    private function handleUserLogin()
    {
        $v = new Validator(Flight::request()->data);
        $v->rule('required', ['username', 'password']);

        if (!$v->validate()) {
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => Flight::request()->data->username ?? '',
                'errorUsername' => $v->errors('username')[0] ?? '',
                'errorPassword' => $v->errors('password')[0] ?? '',
                'voucherCode' => '',
                'errorVoucher' => '',
                'activeTab' => 'login'
            ]);
            return;
        }

        $username = Flight::request()->data->username;
        $password = Flight::request()->data->password;

        $db = Flight::db();
        $stmt = $db->prepare('SELECT id, password FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if($user === false) {
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => $username,
                'errorUsername' => 'Invalid username',
                'errorPassword' => '',
                'voucherCode' => '',
                'errorVoucher' => '',
                'activeTab' => 'login'
            ]);
            return;
        }

        if ($user && password_verify($password, $user['password'])) {
            Flight::session()->set('id', $user['id']);
            Flight::redirect('/');
        } else {
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => $username,
                'errorUsername' => '',
                'errorPassword' => 'Invalid password',
                'voucherCode' => '',
                'errorVoucher' => '',
                'activeTab' => 'login'
            ]);
        }
    }

    private function handleVoucherLogin()
    {
        $v = new Validator(Flight::request()->data);
        $v->rule('required', ['voucher_code']);

        if (!$v->validate()) {
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => '',
                'errorUsername' => '',
                'errorPassword' => '',
                'voucherCode' => Flight::request()->data->voucher_code ?? '',
                'errorVoucher' => $v->errors('voucher_code')[0] ?? '',
                'activeTab' => 'voucher'
            ]);
            return;
        }

        $voucherCode = Flight::request()->data->voucher_code;
        $voucher = \Phuppi\Voucher::findByCode($voucherCode);

        if (!$voucher) {
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => '',
                'errorUsername' => '',
                'errorPassword' => '',
                'voucherCode' => $voucherCode,
                'errorVoucher' => 'Invalid voucher code',
                'activeTab' => 'voucher'
            ]);
            return;
        }

        if ($voucher->isExpired() || $voucher->isDeleted()) {
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => '',
                'errorUsername' => '',
                'errorPassword' => '',
                'voucherCode' => $voucherCode,
                'errorVoucher' => 'Voucher is not valid',
                'activeTab' => 'voucher'
            ]);
            return;
        }

        // Redeem the voucher
        $voucher->redeemed_at = date('Y-m-d H:i:s');
        $voucher->save();

        Flight::session()->set('voucher_id', $voucher->id);
        // Flight::session()->set('id', $voucher->user_id);
        Flight::redirect('/');
    }

    public function logout()
    {
        // Clear session data and destroy session
        Flight::session()->clear();
        Flight::session()->destroy(session_id());

        // Regenerate session ID to prevent session fixation
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Set headers to prevent caching of logout response
        Flight::response()->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        Flight::response()->header('Pragma', 'no-cache');
        Flight::response()->header('Expires', '0');

        Flight::redirect('/login');
    }

    public function listUsers()
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::redirect('/login');
        }

        $user = Flight::user();
        if (!$user || !$user->can(UserPermission::LIST)) {
            Flight::halt(403, 'Forbidden');
        }

        $users = \Phuppi\User::findAll();
        // read the filenames from the avatars directory
        $avatars = scandir(Flight::get('flight.public.path') . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'default-avatars');
        $avatars = array_values(array_filter($avatars, fn($file) => str_ends_with($file, '.png')));

        $filePermissions = array_map(fn($permission) => ['value' => $permission->value, 'label' => $permission->label()], FilePermission::cases());
        $notePermissions = array_map(fn($permission) => ['value' => $permission->value, 'label' => $permission->label()], NotePermission::cases());
        $userPermissions = array_map(fn($permission) => ['value' => $permission->value, 'label' => $permission->label()], UserPermission::cases());
        $voucherPermissions = array_map(fn($permission) => ['value' => $permission->value, 'label' => $permission->label()], VoucherPermission::cases());
        
        $userData = array_map(function($user) {
            return [
                'id' => $user->id,
                'username' => $user->username,
                'created_at' => $user->created_at,
                'isAdmin' => $user->hasRole('admin'),
                'allowedPermissions' => $user->allowedPermissions(),
                'roles' => $user->getRoles(),
                'fileStats' => $user->getFileStats()
            ];
        }, $users);

        Flight::render('users.latte', [
            'can' => [
                'user_list' => $user->can(UserPermission::LIST),
                'user_view' => $user->can(UserPermission::VIEW),
                'user_permit' => $user->can(UserPermission::PERMIT),
                'user_create' => $user->can(UserPermission::CREATE),
                'user_delete' => $user->can(UserPermission::DELETE),
            ],
            'users' => $userData,
            'avatars' => $avatars,
            'filePermissions' => $filePermissions,
            'notePermissions' => $notePermissions,
            'userPermissions' => $userPermissions,
            'voucherPermissions' => $voucherPermissions
        ]);
    }

    public function addPermission($userId)
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }
        $currentUser = Flight::user();
        if (!$currentUser) {
            Flight::halt(403, 'Forbidden');
        }

        $targetUser = \Phuppi\User::findById($userId);

        if(!$currentUser->can(UserPermission::PERMIT, $targetUser)) {
            Flight::halt(403, 'Forbidden');
        }

        if (!$targetUser) {
            Flight::halt(404, 'User not found');
        }

        if($currentUser->id === $targetUser->id) {
            Flight::halt(403, 'Forbidden');
        }

        $data = Flight::request()->data;
        $permission = $data->permission ?? null;
        if (!$permission) {
            Flight::halt(400, 'Permission required');
        }

        // Validate permission is valid
        $allPermissions = array_merge(
            array_column(FilePermission::cases(), 'value'),
            array_column(NotePermission::cases(), 'value'),
            array_column(UserPermission::cases(), 'value'),
            array_column(VoucherPermission::cases(), 'value')
        );
        if (!in_array($permission, $allPermissions)) {
            Flight::halt(400, 'Invalid permission');
        }

        $db = Flight::db();
        $stmt = $db->prepare('INSERT OR REPLACE INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $permission, json_encode(true)]);

        Flight::json(['success' => true]);
    }

    public function removePermission($userId)
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }
        $currentUser = Flight::user();
        if (!$currentUser) {
            Flight::halt(403, 'Forbidden');
        }

        $targetUser = \Phuppi\User::findById($userId);

        if(!$currentUser->can(UserPermission::PERMIT, $targetUser)) {
            Flight::halt(403, 'Forbidden');
        }

        if (!$targetUser) {
            Flight::halt(404, 'User not found');
        }

        if($currentUser->id === $targetUser->id) {
            Flight::halt(403, 'Forbidden');
        }

        $data = Flight::request()->data;
        $permission = $data->permission ?? null;
        if (!$permission) {
            Flight::halt(400, 'Permission required');
        }

        // Validate permission is valid
        $allPermissions = array_merge(
            array_column(FilePermission::cases(), 'value'),
            array_column(NotePermission::cases(), 'value'),
            array_column(UserPermission::cases(), 'value'),
            array_column(VoucherPermission::cases(), 'value')
        );
        if (!in_array($permission, $allPermissions)) {
            Flight::halt(400, 'Invalid permission');
        }

        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM user_permissions WHERE user_id = ? AND permission_name = ?');
        $stmt->execute([$userId, $permission]);

        Flight::json(['success' => true]);
    }

    public function deleteUser($userId)
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }
        $currentUser = Flight::user();
        if (!$currentUser) {
            Flight::halt(403, 'Forbidden');
        }

        $targetUser = \Phuppi\User::findById($userId);

        if(!$currentUser->can(UserPermission::DELETE, $targetUser)) {
            Flight::halt(403, 'Forbidden');
        }

        if (!$targetUser) {
            Flight::halt(404, 'User not found');
        }

        if($currentUser->id === $targetUser->id) {
            Flight::halt(403, 'Forbidden');
        }

        if ($targetUser->delete()) {
            Flight::json(['success' => true]);
        } else {
            Flight::halt(500, 'Failed to delete user');
        }
    }

    public function createUser()
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }

        $currentUser = Flight::user();
        if (!$currentUser || !$currentUser->can(UserPermission::CREATE)) {
            Flight::halt(403, 'Forbidden');
        }

        if (Flight::request()->method !== 'POST') {
            Flight::halt(405, 'Method not allowed');
        }

        $data = Flight::request()->data;
        $username = trim($data->username ?? '');
        $password = $data->password ?? '';

        $v = new Validator(['username' => $username, 'password' => $password]);
        $v->rule('required', ['username', 'password']);
        $v->rule('lengthMin', 'username', 3);
        $v->rule('lengthMin', 'password', 6);

        if (!$v->validate()) {
            Flight::halt(400, json_encode(['errors' => $v->errors()]));
        }

        // Check if username already exists
        if (\Phuppi\User::findByUsername($username)) {
            Flight::halt(400, json_encode(['errors' => ['username' => ['Username already exists']]]));
        }

        $user = new \Phuppi\User([
            'username' => $username,
            'password' => $password
        ]);

        if ($user->save()) {
            $userData = [
                'id' => $user->id,
                'username' => $user->username,
                'created_at' => $user->created_at,
                'isAdmin' => $user->hasRole('admin'),
                'allowedPermissions' => $user->allowedPermissions(),
                'roles' => $user->getRoles(),
                'fileStats' => $user->getFileStats()
            ];
            Flight::json(['success' => true, 'user' => $userData]);
        } else {
            Flight::halt(500, 'Failed to create user');
        }
    }
}
