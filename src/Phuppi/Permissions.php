<?php

namespace Phuppi;

class Permissions
{
    const LIST = 'list';
    const VIEW = 'view';
    const PUT = 'put';
    const GET = 'get';
    const CREATE = 'create';
    const UPDATE = 'update';
    const DELETE = 'delete';

    public static function all(): array
    {
        return [
            self::LIST,
            self::VIEW,
            self::PUT,
            self::GET,
            self::CREATE,
            self::UPDATE,
            self::DELETE,
        ];
    }

    public static function isValid(string $permission): bool
    {
        return in_array($permission, self::all());
    }
}