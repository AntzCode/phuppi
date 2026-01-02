<?php

/**
 * RequireLogin.php
 *
 * Middleware class for checking if the current user is authenticated either by login or voucher and redirecting to login if not.
 * 
 * @package Phuppi\Permissions\Middleware
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Permissions\Middleware;

use Flight;

class RequireLogin
{
    /**
     * Checks if the current user is authenticated either by login or voucher and redirect to login if not.
     *
     * @return bool True if the current user is authenticated either by login or voucher, redirect to login otherwise.
     */
    public function before() {
        if(!(new IsAuthenticated())->before()) {
            Flight::redirect('/login');
        }
        return true;
    }


}

