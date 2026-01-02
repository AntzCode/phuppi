<?php

/**
 * HasPermission.php
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

class HasPermission
{
    /**
     * Checks if the current user is authenticated by login.
     *
     * @return bool True if the current user is authenticated by login, false otherwise.
     */
    public function before($args) {
        var_dump($args);exit(__FILE__.__LINE__);
        $permissionChecker = Flight::get('permissionChecker');
        return (bool) $permissionChecker->hasPermission();
    }


}

