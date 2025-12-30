<?php

namespace Phuppi\Controllers;

use Flight;
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
            'error' => ''
        ]);
    }

    private function handleLogin()
    {
        $v = new Validator(Flight::request()->data);
        $v->rule('required', ['username', 'password']);

        if (!$v->validate()) {
            if($v->errors('username')) {
                Flight::messages()->addError($v->errors('username')[0], 'Invalid credentials');
            }
            if($v->errors('password')) {
                Flight::messages()->addError($v->errors('password')[0], 'Invalid credentials');
            }
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => Flight::request()->data->username ?? '',
                'errorUsername' => $v->errors('username')[0] ?? '',
                'errorPassword' => $v->errors('password')[0] ?? '',
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
            Flight::messages()->addError('Wrong username', 'Invalid credentials');
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => $username,
                'errorUsername' => 'Invalid username'
            ]);
            return;
        }

        if ($user && password_verify($password, $user['password'])) {
            Flight::session()->set('id', $user['id']);
            Flight::redirect('/');
        } else {
            Flight::messages()->addError('Wrong password', 'Invalid credentials');
            Flight::render('login.latte', [
                'formUrl' => '/login',
                'username' => $username,
                'errorPassword' => 'Invalid password'
            ]);
        }
    }

    public function logout()
    {
        Flight::session()->destroy(session_id());
        Flight::redirect('/login');
    }
}
