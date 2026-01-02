<?php

/**
 * FilePermission.php
 *
 * FilePermission enum for defining file-related permissions in the Phuppi application.
 *
 * @package Phuppi\Permissions
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Permissions;

enum FilePermission: string
{
    case LIST = 'file_list';
    case VIEW = 'file_view';
    case PUT = 'file_put';
    case GET = 'file_get';
    case CREATE = 'file_create';
    case UPDATE = 'file_update';
    case DELETE = 'file_delete';

    /**
     * Returns the human-readable label for the permission.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::LIST => 'List Files',
            self::VIEW => 'View File',
            self::PUT => 'Upload File',
            self::GET => 'Download File',
            self::CREATE => 'Create File',
            self::UPDATE => 'Update File',
            self::DELETE => 'Delete File',
            default => throw new \LogicException('Undefined label for FilePermission::' . $this->value),
        };
    }

    /**
     * Creates a FilePermission enum from a string value.
     *
     * @param string $permission The permission string
     * @return self
     * @throws \LogicException If the permission is undefined
     */
    public static function fromString(string $permission): self
    {
        return match ($permission) {
            'file_list' => self::LIST,
            'file_view' => self::VIEW,
            'file_put' => self::PUT,
            'file_get' => self::GET,
            'file_create' => self::CREATE,
            'file_update' => self::UPDATE,
            'file_delete' => self::DELETE,
            default => throw new \LogicException('Undefined FilePermission::' . $permission),
        };
    }
}
