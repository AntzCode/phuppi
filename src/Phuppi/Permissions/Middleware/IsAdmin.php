<?php

/**
 * IsAdmin.php
 *
 * Middleware class for checking if the current user has the "admin" role.
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

class IsAdmin
{
    /**
     * Checks if the current user has the "admin" role.
     *
     * @return bool True if the current user has the "admin" role, false otherwise.
     */
    public function before() {
        return Flight::user()->hasRole('admin');
    }


}

