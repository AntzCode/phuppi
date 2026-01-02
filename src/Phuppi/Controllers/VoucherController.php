<?php

/**
 * VoucherController.php
 *
 * VoucherController class for handling voucher-related operations in the Phuppi application.
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\Helper;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\VoucherPermission;

class VoucherController
{
    /**
     * Lists all vouchers for the authenticated user.
     *
     * @return void
     */
    public function listVouchers(): void
    {

        $user = Flight::user();
        
        $vouchers = \Phuppi\Voucher::findAll();
        $voucherData = array_map(function ($voucher) {
            return [
                'id' => $voucher->id,
                'voucher_code' => $voucher->voucher_code,
                'created_at' => $voucher->created_at,
                'expires_at' => $voucher->expires_at,
                'redeemed_at' => $voucher->redeemed_at,
                'valid_for' => $voucher->valid_for,
                'notes' => $voucher->notes,
                'is_expired' => $voucher->isExpired(),
                'is_redeemed' => $voucher->isRedeemed(),
                'is_deleted' => $voucher->isDeleted(),
                'allowedPermissions' => array_keys($voucher->getPermissions()),
                'fileStats' => $voucher->getFileStats()
            ];
        }, $vouchers);

        $filePermissions = array_map(fn($permission) => ['value' => $permission->value, 'label' => $permission->label()], FilePermission::cases());
        $notePermissions = array_map(fn($permission) => ['value' => $permission->value, 'label' => $permission->label()], NotePermission::cases());

        Flight::render('vouchers.latte', [
            'can' => [
                'voucher_list' => $user->can(VoucherPermission::LIST),
                'voucher_view' => $user->can(VoucherPermission::VIEW),
                'voucher_create' => $user->can(VoucherPermission::CREATE),
                'voucher_update' => $user->can(VoucherPermission::UPDATE),
                'voucher_delete' => $user->can(VoucherPermission::DELETE),
            ],
            'vouchers' => $voucherData,
            'filePermissions' => $filePermissions,
            'notePermissions' => $notePermissions
        ]);
    }

    /**
     * Creates a new voucher.
     *
     * @return void
     */
    public function createVoucher(): void
    {
        $user = Flight::user();
        $data = Flight::request()->data;
        $voucherCode = trim($data->voucher_code ?? '');
        $notes = ''; // No notes field in form
        $validForStr = $data->valid_for ?? '1h';

        // Convert valid_for to hours
        $validFor = $this->parseValidFor($validForStr);
        $expiresAt = $validFor ? date('Y-m-d H:i:s', strtotime("+$validFor hours")) : null;

        // Use provided voucher code or generate unique one
        if (empty($voucherCode)) {
            $voucherCode = $this->generateVoucherCode();
        } elseif (\Phuppi\Voucher::findByCode($voucherCode)) {
            Flight::halt(400, 'Voucher code already exists');
        }

        $voucher = new \Phuppi\Voucher([
            'user_id' => $user->id,
            'voucher_code' => $voucherCode,
            'session_id' => session_id(),
            'valid_for' => $validFor,
            'expires_at' => $expiresAt,
            'notes' => $notes
        ]);

        if ($voucher->save()) {
            $voucherData = [
                'id' => $voucher->id,
                'voucher_code' => $voucher->voucher_code,
                'created_at' => $voucher->created_at,
                'expires_at' => $voucher->expires_at,
                'valid_for' => $voucher->valid_for,
                'notes' => $voucher->notes,
                'is_expired' => $voucher->isExpired(),
                'is_redeemed' => $voucher->isRedeemed(),
                'is_deleted' => $voucher->isDeleted(),
                'allowedPermissions' => array_keys($voucher->getPermissions()),
                'fileStats' => $voucher->getFileStats()
            ];
            Flight::json(['success' => true, 'voucher' => $voucherData]);
        } else {
            Flight::halt(500, 'Failed to create voucher');
        }
    }

    /**
     * Updates a voucher.
     *
     * @param int $id The voucher ID.
     * @return void
     */
    public function updateVoucher($id): void
    {

        $voucher = \Phuppi\Voucher::findById($id);
        if (!Helper::can(VoucherPermission::UPDATE, $voucher)) {
            Flight::halt(403, 'Forbidden');
        }

        if(!$voucher) {
            Flight::halt(404, 'Voucher not found');
        }

        $data = Flight::request()->data;
        
        $voucher->notes = trim($data->notes ?? $voucher->notes);
        
        if (isset($data->valid_for)) {
            $voucher->valid_for = $this->parseValidFor($data->valid_for);
            $voucher->expires_at = $voucher->valid_for ? date('Y-m-d H:i:s', strtotime("+$voucher->valid_for hours")) : null;
        }

        if ($voucher->save()) {
            Flight::json(['success' => true]);
        } else {
            Flight::halt(500, 'Failed to update voucher');
        }
    }

    /**
     * Deletes a voucher.
     *
     * @param int $id The voucher ID.
     * @return void
     */
    public function deleteVoucher($id): void
    {

        $voucher = \Phuppi\Voucher::findById($id);
        
        if (!Helper::can(VoucherPermission::DELETE, $voucher)) {
            Flight::halt(403, 'Forbidden');
        }

        if (!$voucher) {
            Flight::halt(404, 'Voucher not found');
        }

        if ($voucher->delete()) {
            Flight::json(['success' => true]);
        } else {
            Flight::halt(500, 'Failed to delete voucher');
        }
    }

    /**
     * Adds a permission to a voucher.
     *
     * @param int $id The voucher ID.
     * @return void
     */
    public function addPermission($id): void
    {

        $voucher = \Phuppi\Voucher::findById($id);

        if (!Helper::can(VoucherPermission::UPDATE, $voucher)) {
            Flight::halt(403, 'Forbidden');
        }

        if (!$voucher) {
            Flight::halt(404, 'Voucher not found');
        }

        $data = Flight::request()->data;
        $permission = $data->permission ?? null;
        if (!$permission) {
            Flight::halt(400, 'Permission required');
        }

        $allPermissions = array_merge(
            array_column(FilePermission::cases(), 'value'),
            array_column(NotePermission::cases(), 'value')
        );
        if (!in_array($permission, $allPermissions)) {
            Flight::halt(400, 'Invalid permission');
        }

        $permissions = $voucher->getPermissions();
        $permissions[$permission] = json_encode(true);
        $voucher->setPermissions($permissions);

        Flight::json(['success' => true]);
    }

    /**
     * Removes a permission from a voucher.
     *
     * @param int $id The voucher ID.
     * @return void
     */
    public function removePermission($id): void
    {

        $voucher = \Phuppi\Voucher::findById($id);
        if (!Helper::can(VoucherPermission::UPDATE, $voucher)) {
            Flight::halt(403, 'Forbidden');
        }

        if (!$voucher) {
            Flight::halt(404, 'Voucher not found');
        }

        $data = Flight::request()->data;
        $permission = $data->permission ?? null;
        if (!$permission) {
            Flight::halt(400, 'Permission required');
        }

        $permissions = $voucher->getPermissions();
        unset($permissions[$permission]);
        $voucher->setPermissions($permissions);

        Flight::json(['success' => true]);
    }

    /**
     * Parses the valid_for string into hours.
     *
     * @param string $validFor The valid_for string (e.g., '1h', '2d').
     * @return ?int The number of hours or null for forever.
     */
    private function parseValidFor(string $validFor): ?int
    {
        switch ($validFor) {
            case '15m':
                return 0.25;
            case '1h':
                return 1;
            case '3h':
                return 3;
            case '6h':
                return 6;
            case '12h':
                return 12;
            case '24h':
                return 24;
            case '2d':
                return 48;
            case '3d':
                return 72;
            case '1w':
                return 168;
            case '2w':
                return 336;
            case '3w':
                return 504;
            case '1M':
                return 720; // approx 30 days
            case '3M':
                return 2160; // approx 90 days
            case '6M':
                return 4320; // approx 180 days
            case '1y':
                return 8760; // approx 365 days
            case '2y':
                return 17520;
            case '3y':
                return 26280;
            case 'forever':
                return null;
            default:
                return 1; // default 1 hour
        }
    }

    /**
     * Generates a unique voucher code.
     *
     * @return string The unique voucher code.
     */
    private function generateVoucherCode(): string
    {
        $animals = ['Bear', 'Cat', 'Cheetah', 'Chicken', 'Crocodile', 'Doe', 'Duck', 'Elephant', 'Fox', 'Frog', 'Horse', 'Lemur', 'Lion', 'Llama', 'Mouse', 'Otter', 'Owl', 'Panda', 'Penguin', 'Pig', 'Raccoon', 'Rhino', 'Sheep', 'Sloth', 'Snake', 'Tiger', 'Yak', 'Zebra'];
        $emotions = ['Happy', 'Sad', 'Angry', 'Sleepy', 'Excited', 'Calm', 'Playful', 'Grumpy', 'Cheerful', 'Lazy'];

        do {
            $emotion = $emotions[array_rand($emotions)];
            $animal = $animals[array_rand($animals)];
            $number = rand(100, 999);
            $code = $emotion . $animal . $number;
            $existing = \Phuppi\Voucher::findByCode($code);
        } while ($existing);

        return $code;
    }
}
