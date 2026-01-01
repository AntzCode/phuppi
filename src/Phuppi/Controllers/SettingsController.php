<?php

namespace Phuppi\Controllers;

use Flight;
use Phuppi\User;

class SettingsController
{
    public function index()
    {
        $sessionId = Flight::session()->get('id');
        if (!$sessionId) {
            Flight::redirect('/login');
        }

        $user = User::findById($sessionId);
        if (!$user || !$user->hasRole('admin')) {
            Flight::halt(403, 'Forbidden');
        }

        Flight::render('settings.latte');
    }

}