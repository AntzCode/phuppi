<?php

namespace Fuppi;

require_once('App.php');

use Fuppi\Abstract\HasUser;
use Fuppi\Abstract\Model;
use PDO;

class Voucher extends Model
{
    use HasUser;

    protected \Fuppi\App $app;
    protected \Fuppi\Db $db;

    protected string $_tablename = 'fuppi_vouchers';
    protected string $_primaryKeyColumnName = 'voucher_id';

    protected $data = [
        'voucher_id' => 0,
        'user_id' => 0,
        'voucher_code' => '',
        'session_id' => '',
        'created_at' => '',
        'updated_at' => '',
        'expires_at' => null,
        'redeemed_at' => null,
        'deleted_at' => null,
        'valid_for' => null,
        'notes' => ''
    ];

    public static function getOne(int $id): self
    {
        return parent::getOne($id);
    }

    public static function findByVoucherCode(string $voucherCode)
    {
        $instance = new self();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `voucher_code` = :voucher_code AND `deleted_at` IS NULL');
        if ($statement->execute(['voucher_code' => $voucherCode]) && $row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $instance->setData($instance->fromDb($row));
            return $instance;
        }
    }

    public static function deleteOne(int $voucherId)
    {
        if ($voucher = self::getOne($voucherId)) {
            $db = \Fuppi\App::getInstance()->getDb();
            $statement = $db->getPdo()->query('UPDATE `' . $voucher->_tablename . '` SET `deleted_at` = CURRENT_TIMESTAMP WHERE `' . $voucher->_primaryKeyColumnName . '` = :id');
            return $statement->execute(['id' => $voucherId]);
        }
        return false;
    }


    public static function getAll(array $ids = null): array
    {
        $className = get_called_class();
        $instance = new $className();
        $userPermissions = [];
        $db = \Fuppi\App::getInstance()->getDb();
        if (!is_null($ids)) {
            $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `' . $instance->_primaryKeyColumnName . '` IN :ids AND `deleted_at` IS NULL');
            $results = $statement->execute(['ids' => $ids]);
        } else {
            $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `deleted_at` IS NULL');
            $results = $statement->execute();
        }
        if ($results) {
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $userPermissionData) {
                $userPermission = new $className();
                $userPermission->setData($userPermission->fromDb($userPermissionData));
                $userPermissions[] = $userPermission;
            }
        }
        return $userPermissions;
    }
}
