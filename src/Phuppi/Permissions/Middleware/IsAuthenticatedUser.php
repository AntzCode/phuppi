<?php

/**
 * IsAuthenticatedUser.php
 *
 * Middleware class for checking if the current user is authenticated by login.
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

class IsAuthenticatedUser
{
    /**
     * Checks if the current user is authenticated by login.
     *
     * @return bool True if the current user is authenticated by login, false otherwise.
     */
    public function before() {
        return (bool) Flight::user()->id > 0;
    }


}

