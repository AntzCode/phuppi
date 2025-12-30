<?php

namespace Phuppi\Controllers;

use Flight;
use Valitron\Validator;

class InstallController
{
    public function index()
    {
        Flight::render('installer.latte', [
            'formUrl' => '/install',
            'username' => '',
            'password' => ''
        ]);
    }

    public function install()
    {
        $v = new Validator(Flight::request()->data);
        $v->rule('required', ['username', 'password']);
        $v->rule('lengthMin', 'password', 6);
        $v->rule('lengthMin', 'username', 3);

        if (!$v->validate()) {
            Flight::render('installer.latte', [
                'formUrl' => '/install',
                'username' => Flight::request()->data->username ?? '',
                'password' => Flight::request()->data->password ?? '',
                'errorUsername' => $v->errors('username')[0] ?? '',
                'errorPassword' => $v->errors('password')[0] ?? ''
            ]);
            return;
        }

        $username = Flight::request()->data->username;
        $password = Flight::request()->data->password;

        // Run V2 migration
        require_once(Flight::get('flight.root.path') . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'migrations'  . DIRECTORY_SEPARATOR . '001_install_migration.php');

        // Create superadmin with provided credentials
        $db = Flight::db();
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $statement = $db->prepare('INSERT INTO users (username, password, created_at, updated_at, notes) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)');
        $statement->execute([$username, $hashedPassword, 'Primary Superadmin User']);

        $adminUserId = $db->lastInsertId();

        $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
        $statement->execute([$adminUserId, 'IS_ADMINISTRATOR', json_encode(true)]);

        Flight::session()->set('id', $adminUserId);

        Flight::redirect('/');
    }
}