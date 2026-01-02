<?php

/**
 * UserPermission.php
 *
 * UserPermission enum for defining user-related permissions in the Phuppi application.
 *
 * @package Phuppi\Permissions
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Permissions;

enum UserPermission: string
{
    case LIST = 'user_list';
    case VIEW = 'user_view';
    case PERMIT = 'user_permit';
    case CREATE = 'user_create';
    case UPDATE = 'user_update';
    case DELETE = 'user_delete';

    /**
     * Returns the human-readable label for the permission.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::LIST => 'List Users',
            self::VIEW => 'View User',
            self::PERMIT => 'Permit User',
            self::CREATE => 'Create User',
            self::UPDATE => 'Update User',
            self::DELETE => 'Delete User',
            default => throw new \LogicException('Undefined label for UserPermission::' . $this->value),
        };
    }

    /**
     * Creates a UserPermission enum from a string value.
     *
     * @param string $permission The permission string
     * @return self
     * @throws \LogicException If the permission is undefined
     */
    public static function fromString(string $permission): self
    {
        return match ($permission) {
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
