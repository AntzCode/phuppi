<?php

namespace Fuppi;

use Fuppi\Abstract\Model;
use PDO;

class VoucherPermission extends Model
{
    const IS_ADMINISTRATOR = 'IS_ADMINISTRATOR';
    const UPLOADEDFILES_PUT = 'UPLOADEDFILES_PUT';
    const UPLOADEDFILES_DELETE = 'UPLOADEDFILES_DELETE';
    const UPLOADEDFILES_LIST = 'UPLOADEDFILES_LIST';
    const UPLOADEDFILES_LIST_ALL = 'UPLOADEDFILES_LIST_ALL';
    const UPLOADEDFILES_READ = 'UPLOADEDFILES_READ';
    const USERS_PUT = 'USERS_PUT';
    const USERS_DELETE = 'USERS_DELETE';
    const USERS_LIST = 'USERS_LIST';
    const USERS_READ = 'USERS_READ';

    protected string $_tablename = 'fuppi_voucher_permissions';
    protected string $_primaryKeyColumnName = 'voucher_permission_id';

    protected $data = [
        'voucher_permission_id' => 0,
        'voucher_id' => 0,
        'permission_name' => '',
        'permission_value' => ''
    ];

    protected $jsonColumnNames = ['permission_value'];

    public static function isVoucherPermitted(string $permissionName, Voucher $voucher): bool
    {
        if ($permission = self::getVoucherPermission($permissionName, $voucher)) {
            if ($permission->permission_value === true) {
                return true;
            }
        }
        if ($permission = self::getVoucherPermission(self::IS_ADMINISTRATOR, $voucher)) {
            if ($permission->permission_value) {
                return true;
            }
        }
        return false;
    }

    public static function getVoucherPermissions(Voucher $voucher)
    {
        $instance = new self();
        $instances = [];
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `voucher_id` = :voucher_id');
        if ($statement->execute(['voucher_id' => $voucher->voucher_id]) && $permissions = $statement->fetchAll(PDO::FETCH_ASSOC)) {
            foreach ($permissions as $permissionData) {
                $instance = new self();
                $instance->setData($instance->fromDb($permissionData));
                $instances[] = $instance;
            }
            return $instances;
        }
        return null;
    }

    public static function getVoucherPermission(string $permissionName, Voucher $voucher): ?VoucherPermission
    {
        $instance = new self();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `voucher_id` = :voucher_id AND `permission_name` = :permission_name');
        if ($statement->execute(['voucher_id' => $voucher->voucher_id, 'permission_name' => $permissionName]) && $permissionData = $statement->fetch(PDO::FETCH_ASSOC)) {
            $instance->setData($instance->fromDb($permissionData));
            return $instance;
        }
        return null;
    }
}
