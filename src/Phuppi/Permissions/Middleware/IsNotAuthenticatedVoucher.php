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

class IsNotAuthenticatedVoucher
{
    /**
     * Checks if the current user is authenticated by voucher.
     *
     * @return bool False if the current user is authenticated by voucher, true otherwise.
     */
    public function before()
    {
        return (bool) intval(Flight::voucher()->id ?? 0) <= 0;
    }
}
