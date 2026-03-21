<?php

/**
 * Helper.php
 *
 * Helper class for utility functions in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi;

use Flight;

use League\CommonMark\Environment\Environment;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;

use Phuppi\Note;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;
use Phuppi\UploadedFile;
use Phuppi\User;
use Phuppi\Voucher;

require_once(__DIR__ . '/../commonmark/commonmark.phar');

class Helper
{

    /**
     * Gets the Phuppi version.
     *
     * @return string The version string.
     */
    public static function getPhuppiVersion(): string
    {
        return '2.5.0';
    }

    /**
     * Gets user messages of a specific type and renders them.
     *
     * @param mixed $type The type of messages.
     * @return ?string The rendered messages or null if none.
     */
    public static function getUserMessages($type): ?string
    {

        if ($messages = Flight::messages()->getUserMessages($type)) {

            $latte = Flight::latte();

            $attitude ??= null;
            $color ??= null;
            $componentId ??= '';
            $header ??= null;
            $icon ??= null;
            $type ??= null;

            foreach ($messages as $message) {
                $attitude = $attitude ?? $message->attitude;
                $color = $color ?? $message->color;
                $componentId .= $message->componentId ?? $message->id ?? 'msg_' . rand();
                $header = $header ?? !empty($message->title) ? $message->title : null;
                $icon = $icon ?? $message->icon;
                $type = $type ?? $message->type;
            }

            return $latte->renderToString('messages.latte', [
                'attitude' => $attitude,
                'color' => $color,
                'componentId' => $componentId,
                'header' => $header,
                'icon' => $icon,
                'messages' => $messages,
                'type' => $type,
            ]);
        } else {
            return null;
        }
    }

    /**
     * Gets the current user ID.
     *
     * @return int|null The user ID or null if not logged in.
     */
    public static function getUserId(): ?int
    {
        return Flight::user()?->id ?? null;
    }

    /**
     * Gets the current voucher ID.
     *
     * @return int|null The voucher ID or null if not set.
     */
    public static function getVoucherId(): ?int
    {
        return Flight::voucher()?->id ?? null;
    }

    /**
     * Checks if the user is authenticated.
     *
     * @return bool True if authenticated, false otherwise.
     */
    public static function isAuthenticated(): bool
    {
        return Flight::session()->get('id') || Flight::session()->get('voucher_id');
    }

    /**
     * Checks if the current user or voucher can perform the permission on the subject.
     *
     * @param NotePermission|UserPermission|VoucherPermission|FilePermission|string $permission The permission to check.
     * @param null|Note|UploadedFile|User|Voucher $subject The subject of the action.
     * @return bool True if allowed, false otherwise.
     */
    public static function can(NotePermission|UserPermission|VoucherPermission|FilePermission|string $permission, null|Note|UploadedFile|User|Voucher $subject = null): bool
    {
        $user = Flight::user();
        $voucher = Flight::voucher();
        if ($voucher->id) {
            return $voucher->can($permission, $subject);
        } else if ($user->id) {
            return $user->can($permission, $subject);
        } else {
            return false;
        }
    }

    /**
     * Checks if the script is running from the command line interface (CLI).
     *
     * @return bool True if running in CLI mode, false otherwise.
     */
    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }


    /**
     * Converts wiki markup to HTML using Wiky.php library.
     *
     * @param string $content The wiki markup content to convert
     * @return string The converted HTML content
     */
    public static function convertMarkdownToHtml(string $content): string
    {

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $converter = new MarkdownConverter($environment);

        return $converter->convert($content);
    }

    /**
     * Formats bytes as human-readable string
     *
     * @param int|float $bytes The number of bytes
     * @return string Formatted string (e.g., "1.5 MB", "3.2 GB")
     */
    public static function formatBytes($bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = floor(log($bytes, $k));

        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

}
