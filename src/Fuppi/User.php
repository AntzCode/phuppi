<?php

namespace Fuppi;

require_once('App.php');

use Fuppi\Abstract\Model;

class User extends Model
{
    protected \Fuppi\App $app;
    protected \Fuppi\Db $db;

    protected static string $tablename = 'fuppi_users';
    protected static string $primaryKeyColumnName = 'user_id';

    protected $data = [
        'user_id' => 0,
        'username' => '',
        'password' => ''
    ];

    public static function getOne(int $id): ?User
    {
        $user = new self();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($user->getData())) . '` FROM `' . self::$tablename . '` WHERE `' . self::$primaryKeyColumnName . '` = :id');
        if ($statement->execute(['id' => $id])) {
            $user->setData($statement->fetch());
            return $user;
        }
    }

    public function canUpload(): bool
    {
        return $this->user_id > 0;
    }
}
