<?php

/**
 * IsAuthenticatedVoucher.php
 *
 * Middleware class for checking if the current user is authenticated by voucher.
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

class IsAuthenticatedVoucher
{
    /**
     * Checks if the current user is authenticated by voucher.
     *
     * @return bool True if the current user is authenticated by voucher, false otherwise.
     */
    public function before() {
        return (bool) Flight::voucher()->id > 0;
    }


}

