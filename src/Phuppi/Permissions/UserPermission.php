<?php

namespace Phuppi\Permissions;

enum UserPermission: string
{
    case LIST = 'user_list';
    case VIEW = 'user_view';
    case PERMIT = 'user_permit';
    case CREATE = 'user_create';
    case UPDATE = 'user_update';
    case DELETE = 'user_delete';

    public function label() {
        return match($this) {
            self::LIST => 'List Users',
            self::VIEW => 'View User',
            self::PERMIT => 'Permit User',
            self::CREATE => 'Create User',
            self::UPDATE => 'Update User',
            self::DELETE => 'Delete User',
            default => throw new \LogicException('Undefined label for UserPermission::' . $this->value),
        };
    }

    public static function fromString(string $permission) {
        return match($permission) {
            'user_list' => self::LIST,
            'user_view' => self::VIEW,
            'user_permit' => self::PERMIT,
            'user_create' => self::CREATE,
            'user_update' => self::UPDATE,
            'user_delete' => self::DELETE,
            default => throw new \LogicException('Undefined UserPermission::' . $permission),
        };
    }
}
