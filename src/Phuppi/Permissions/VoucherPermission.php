<?php

/**
 * VoucherPermission.php
 *
 * VoucherPermission enum for defining voucher-related permissions in the Phuppi application.
 *
 * @package Phuppi\Permissions
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Permissions;

enum VoucherPermission: string
{
    case LIST = 'voucher_list';
    case VIEW = 'voucher_view';
    case CREATE = 'voucher_create';
    case UPDATE = 'voucher_update';
    case DELETE = 'voucher_delete';

    /**
     * Returns the human-readable label for the permission.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::LIST => 'List Vouchers',
            self::VIEW => 'View Voucher',
            self::CREATE => 'Create Voucher',
            self::UPDATE => 'Update Voucher',
            self::DELETE => 'Delete Voucher',
            default => throw new \LogicException('Undefined label for VoucherPermission::' . $this->value),
        };
    }

    /**
     * Creates a VoucherPermission enum from a string value.
     *
     * @param string $permission The permission string
     * @return self
     * @throws \LogicException If the permission is undefined
     */
    public static function fromString(string $permission): self
    {
        return match ($permission) {
            'voucher_list' => self::LIST,
            'voucher_view' => self::VIEW,
            'voucher_create' => self::CREATE,
            'voucher_update' => self::UPDATE,
            'voucher_delete' => self::DELETE,
            default => throw new \LogicException('Undefined VoucherPermission::' . $permission),
        };
    }
}
