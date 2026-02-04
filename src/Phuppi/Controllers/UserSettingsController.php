<?php

/**
 * UserSettingsController.php
 *
 * UserSettingsController class for managing user settings like password change in the Phuppi application.
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Controllers;

use Flight;
use Valitron\Validator;
use Phuppi\User;

class UserSettingsController
{
    /**
     * Displays the user settings page.
     *
     * @return void
     */
    public function index(): void
    {
        Flight::render('user-settings.latte');
    }

    /**
     * Updates the user's password.
     *
     * @return void
     */
    public function updatePassword(): void
    {
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::json(['error' => 'User not authenticated'], 401);
            return;
        }

        $data = Flight::request()->data;
        $v = new Validator([
            'current_password' => $data->current_password ?? '',
            'new_password' => $data->new_password ?? '',
            'confirm_password' => $data->confirm_password ?? ''
        ]);

        $v->rule('required', ['current_password', 'new_password', 'confirm_password']);
        $v->rule('lengthMin', 'new_password', 6);

        if (!$v->validate()) {
            Flight::json(['errors' => $v->errors()], 400);
            return;
        }

        $currentPassword = $data->current_password;
        $newPassword = $data->new_password;
        $confirmPassword = $data->confirm_password;

        if ($newPassword !== $confirmPassword) {
            Flight::json(['error' => 'New passwords do not match'], 400);
            return;
        }

        if (!$user->authenticate($currentPassword)) {
            Flight::json(['error' => 'Current password is incorrect'], 400);
            return;
        }

        $user->password = $newPassword;
        if ($user->save()) {
            Flight::json(['success' => true]);
        } else {
            Flight::json(['error' => 'Failed to update password'], 500);
        }
    }
}
