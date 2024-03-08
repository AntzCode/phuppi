<?php

namespace Fuppi;

use Fuppi\Abstract\Model;

class UserPermission extends Model
{
    const IS_ADMINISTRATOR = 'IS_ADMINISTRATOR';
    const UPLOADEDFILES_PUT = 'UPLOADEDFILES_PUT';
    const UPLOADEDFILES_DELETE = 'UPLOADEDFILES_DELETE';
    const UPLOADEDFILES_LIST = 'UPLOADEDFILES_LIST';
    const UPLOADEDFILES_READ = 'UPLOADEDFILES_READ';
    const USERS_PUT = 'USERS_PUT';
    const USERS_DELETE = 'USERS_DELETE';
    const USERS_LIST = 'USERS_LIST';
    const USERS_READ = 'USERS_READ';

    protected string $_tablename = 'fuppi_user_permissions';
    protected string $_primaryKeyColumnName = 'user_permission_id';

    protected $data = [
        'user_permission_id' => 0,
        'user_id' => 0,
        'permission_name' => '',
        'permission_value' => ''
    ];
    
    protected $jsonColumnNames = ['permission_value'];

    public static function isUserPermitted(string $permissionName, User $user): bool
    {
        if ($permission = self::getUserPermission($permissionName, $user)) {
            if ($permission->permission_value === true) {
                return true;
            }
        }
        if ($permission = self::getUserPermission(self::IS_ADMINISTRATOR, $user)) {
            if ($permission->permission_value) {
                return true;
            }
        }
        return false;
    }

    public static function getUserPermissions(User $user)
    {
        $instance = new self();
        $instances = [];
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `user_id` = :user_id');
        if ($statement->execute(['user_id' => $user->user_id]) && $permissions = $statement->fetchAll()) {
            foreach ($permissions as $permissionData) {
                $instance = new self();
                $instance->setData($instance->fromDb($permissionData));
                $instances[] = $instance;
            }
            return $instances;
        }
        return null;
    }

    public static function getUserPermission(string $permissionName, User $user): ?UserPermission
    {
        $instance = new self();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `user_id` = :user_id AND `permission_name` = :permission_name');
        if ($statement->execute(['user_id' => $user->user_id, 'permission_name' => $permissionName]) && $permissionData = $statement->fetch()) {
            $instance->setData($instance->fromDb($permissionData));
            return $instance;
        }
        return null;
    }
}
