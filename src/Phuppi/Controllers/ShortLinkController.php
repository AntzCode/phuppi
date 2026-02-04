<?php

/**
 * ShortLinkController.php
 *
 * ShortLinkController for URL shortener.
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.1
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\ShortLink;

class ShortLinkController
{
    /**
     * Lists all short links.
     *
     * @return void
     */
    public function index(): void
    {
        $shortLinks = ShortLink::findAll();
        Flight::render('shortlinks.latte', ['shortLinks' => $shortLinks]);
    }

    /**
     * Shortens a URL.
     *
     * @return void
     */
    public function shortenUrl(): void
    {
        $data = Flight::request()->data;
        $target = $data->target ?? null;
        if (!$target) {
            Flight::halt(400, 'Missing target URL');
        }

        $duration = $data->duration ?? '1h';

        // Calculate expires_at
        $expiresAt = null;
        switch ($duration) {
            case '1h':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                break;
            case '1d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
                break;
            case '1w':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 week'));
                break;
            case '1m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
                break;
            case '3m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 months'));
                break;
            case '6m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+6 months'));
                break;
            case '1y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                break;
            case '3y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 years'));
                break;
            case 'forever':
                $expiresAt = null;
                break;
            default:
                Flight::halt(400, 'Invalid duration');
        }

        $shortLink = new ShortLink();
        $shortLink->target = $target;
        $shortLink->expires_at = $expiresAt;

        if ($shortLink->save()) {
            $shortUrl = Flight::request()->getScheme() . '://' . Flight::request()->servername . '/s/' . $shortLink->shortcode;
            Flight::json(['short_url' => $shortUrl, 'expires_at' => $expiresAt]);
        } else {
            Flight::halt(500, 'Failed to create short link');
        }
    }

    /**
     * Redirects short link to target.
     *
     * @param string $shortcode The shortcode.
     * @return void
     */
    public function redirectShortLink($shortcode): void
    {
        $shortLink = ShortLink::findByShortcode($shortcode);
        if (!$shortLink) {
            Flight::halt(404, 'Short link not found');
        }

        Flight::redirect($shortLink->target);
    }
}
