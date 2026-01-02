<?php

namespace Phuppi\Controllers;

use Flight;
use Phuppi\Permissions\VoucherPermission;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Valitron\Validator;

class VoucherController
{
    public function listVouchers()
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::redirect('/login');
        }

        $user = Flight::user();
        if (!$user || !$user->can(VoucherPermission::LIST)) {
            Flight::halt(403, 'Forbidden');
        }

        $vouchers = \Phuppi\Voucher::findAll();
        $voucherData = array_map(function($voucher) {
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

    public function createVoucher()
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }

        $user = Flight::user();
        if (!$user || !$user->can(VoucherPermission::CREATE)) {
            Flight::halt(403, 'Forbidden');
        }

        $data = Flight::request()->data;
        $notes = trim($data->notes ?? '');
        $validForStr = $data->valid_for ?? '1h';

        // Convert valid_for to hours
        $validFor = $this->parseValidFor($validForStr);
        $expiresAt = $validFor ? date('Y-m-d H:i:s', strtotime("+$validFor hours")) : null;

        // Generate unique voucher code
        $voucherCode = $this->generateVoucherCode();

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

    public function updateVoucher($id)
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }

        $user = Flight::user();
        if (!$user) {
            Flight::halt(403, 'Forbidden');
        }

        $voucher = \Phuppi\Voucher::findById($id);
        if (!$voucher || !$user->can(VoucherPermission::UPDATE, $voucher)) {
            Flight::halt(403, 'Forbidden');
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

    public function deleteVoucher($id)
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }

        $user = Flight::user();
        if (!$user) {
            Flight::halt(403, 'Forbidden');
        }

        $voucher = \Phuppi\Voucher::findById($id);
        if (!$voucher || !$user->can(VoucherPermission::DELETE, $voucher)) {
            Flight::halt(403, 'Forbidden');
        }

        if ($voucher->delete()) {
            Flight::json(['success' => true]);
        } else {
            Flight::halt(500, 'Failed to delete voucher');
        }
    }

    public function addPermission($id)
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }

        $user = Flight::user();
        if (!$user) {
            Flight::halt(403, 'Forbidden');
        }

        $voucher = \Phuppi\Voucher::findById($id);
        if (!$voucher || !$user->can(VoucherPermission::UPDATE, $voucher)) {
            Flight::halt(403, 'Forbidden');
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

    public function removePermission($id)
    {
        if (!\Phuppi\Helper::isAuthenticated()) {
            Flight::halt(401, 'Unauthorized');
        }

        $user = Flight::user();
        if (!$user) {
            Flight::halt(403, 'Forbidden');
        }

        $voucher = \Phuppi\Voucher::findById($id);
        if (!$voucher || !$user->can(VoucherPermission::UPDATE, $voucher)) {
            Flight::halt(403, 'Forbidden');
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

    private function parseValidFor(string $validFor): ?int
    {
        switch ($validFor) {
            case '15m': return 0.25;
            case '1h': return 1;
            case '3h': return 3;
            case '6h': return 6;
            case '12h': return 12;
            case '24h': return 24;
            case '2d': return 48;
            case '3d': return 72;
            case '1w': return 168;
            case '2w': return 336;
            case '3w': return 504;
            case '1M': return 720; // approx 30 days
            case '3M': return 2160; // approx 90 days
            case '6M': return 4320; // approx 180 days
            case '1y': return 8760; // approx 365 days
            case '2y': return 17520;
            case '3y': return 26280;
            case 'forever': return null;
            default: return 1; // default 1 hour
        }
    }

    private function generateVoucherCode(): string
    {
        do {
            $code = bin2hex(random_bytes(16));
            $existing = \Phuppi\Voucher::findByCode($code);
        } while ($existing);

        return $code;
    }
}