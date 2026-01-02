<?php

/**
 * IsAuthenticated.php
 *
 * Middleware class for checking if the current user is authenticated either by login or voucher.
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

class IsAuthenticated
{
    /**
     * Checks if the current user is authenticated either by login or voucher.
     *
     * @return bool True if the current user is authenticated either by login or voucher, false otherwise.
     */
    public function before() {
        return (bool) (Flight::user()->id > 0 || Flight::voucher()->id > 0);
    }


}

