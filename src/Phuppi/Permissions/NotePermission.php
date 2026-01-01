<?php

namespace Phuppi\Permissions;

enum NotePermission: string
{
    case LIST = 'note_list';
    case VIEW = 'note_view';
    case CREATE = 'note_create';
    case UPDATE = 'note_update';
    case DELETE = 'note_delete';


    public function label() {
        return match($this) {
            self::LIST => 'List Notes',
            self::VIEW => 'View Note',
            self::CREATE => 'Create Note',
            self::UPDATE => 'Update Note',
            self::DELETE => 'Delete Note',
            default => throw new \LogicException('Undefined label for NotePermission::' . $this->value),
        };
    }

    public static function fromString(string $permission) {
        return match($permission) {
            'note_list' => self::LIST,
            'note_view' => self::VIEW,
            'note_create' => self::CREATE,
            'note_update' => self::UPDATE,
            'note_delete' => self::DELETE,
            default => throw new \LogicException('Undefined NotePermission::' . $permission),
        };
    }

}
