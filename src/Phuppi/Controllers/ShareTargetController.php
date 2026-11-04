<?php

/**
 * ShareTargetController.php
 *
 * ShareTargetController class for the page that uploads files shared into the installed web app
 * (Web Share Target). The service worker keeps the shared files in the browser; this page uploads them.
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.8.0
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\Helper;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\Middleware\IsAuthenticated;

class ShareTargetController
{
    /**
     * Fallback for a share that reached the server because no service worker was active:
     * there are no files to read here, so send the visitor to the receiving page.
     *
     * @return void
     */
    public function fallback(): void
    {
        Flight::redirect('/share-target/receive', 303);
    }

    /**
     * Shows the receiving page. A visitor who is not logged in is sent to log in first and
     * brought back here afterwards; the shared files stay in the browser meanwhile.
     *
     * @return void
     */
    public function receive(): void
    {
        if (!(new IsAuthenticated())->before()) {
            Flight::session()->set('return_to', '/share-target/receive');
            Flight::redirect('/login');
            return;
        }

        Flight::render('share-target.latte', [
            'canUpload' => Helper::can(FilePermission::PUT) && Helper::can(FilePermission::CREATE),
        ]);
    }
}
