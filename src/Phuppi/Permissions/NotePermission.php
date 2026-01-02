<?php

/**
 * NotePermission.php
 *
 * NotePermission enum for defining note-related permissions in the Phuppi application.
 *
 * @package Phuppi\Permissions
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Permissions;

enum NotePermission: string
{
    case LIST = 'note_list';
    case VIEW = 'note_view';
    case CREATE = 'note_create';
    case UPDATE = 'note_update';
    case DELETE = 'note_delete';


    /**
     * Returns the human-readable label for the permission.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::LIST => 'List Notes',
            self::VIEW => 'View Note',
            self::CREATE => 'Create Note',
            self::UPDATE => 'Update Note',
            self::DELETE => 'Delete Note',
            default => throw new \LogicException('Undefined label for NotePermission::' . $this->value),
        };
    }

    /**
     * Creates a NotePermission enum from a string value.
     *
     * @param string $permission The permission string
     * @return self
     * @throws \LogicException If the permission is undefined
     */
    public static function fromString(string $permission): self
    {
        return match ($permission) {
            'note_list' => self::LIST,
            'note_view' => self::VIEW,
            'note_create' => self::CREATE,
            'note_update' => self::UPDATE,
            'note_delete' => self::DELETE,
            default => throw new \LogicException('Undefined NotePermission::' . $permission),
        };
    }
}
