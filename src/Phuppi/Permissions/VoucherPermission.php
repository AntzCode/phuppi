<?php

namespace Phuppi\Permissions;

enum VoucherPermission: string
{
    case LIST = 'voucher_list';
    case VIEW = 'voucher_view';
    case CREATE = 'voucher_create';
    case UPDATE = 'voucher_update';
    case DELETE = 'voucher_delete';

    public function label() {
        return match($this) {
            self::LIST => 'List Vouchers',
            self::VIEW => 'View Voucher',
            self::CREATE => 'Create Voucher',
            self::UPDATE => 'Update Voucher',
            self::DELETE => 'Delete Voucher',
            default => throw new \LogicException('Undefined label for VoucherPermission::' . $this->value),
        };
    }

    public static function fromString(string $permission) {
        return match($permission) {
            'voucher_list' => self::LIST,
            'voucher_view' => self::VIEW,
            'voucher_create' => self::CREATE,
            'voucher_update' => self::UPDATE,
            'voucher_delete' => self::DELETE,
            default => throw new \LogicException('Undefined VoucherPermission::' . $permission),
        };
    }
}
