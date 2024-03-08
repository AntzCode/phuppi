<?php

namespace Fuppi;

require_once('App.php');

use Fuppi\Abstract\Model;
use PDO;

class User extends Model
{
    protected \Fuppi\App $app;
    protected \Fuppi\Db $db;

    protected string $_tablename = 'fuppi_users';
    protected string $_primaryKeyColumnName = 'user_id';

    protected $data = [
        'user_id' => 0,
        'username' => '',
        'password' => '',
        'created_at' => '',
        'updated_at' => '',
        'disabled_at' => null,
        'notes' => ''
    ];

    public function canUpload(): bool
    {
        return $this->user_id > 0;
    }

    public static function filterUsername(string $username)
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
    }

    public static function getOne(int $id): self
    {
        return parent::getOne($id);
    }

    public static function findByUsername(string $username)
    {
        $instance = new self();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `username` = :username');
        if ($statement->execute(['username' => $username]) && $row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $instance->setData($instance->fromDb($row));
            return $instance;
        }
    }

    public static function disableUser(User $user, string $reasonMessage)
    {
        if (UserPermission::isUserPermitted(UserPermission::IS_ADMINISTRATOR, $user)) {
            fuppi_add_error_message('Not able to disable an administrator');
        } else {
            $adminUserId = \Fuppi\App::getInstance()->getUser()->user_id;
            $adminUsername = \Fuppi\App::getInstance()->getUser()->username;
            $user->disabled_at = date('Y-m-d H:i:s');
            $user->notes = $user->notes . 'disabled at ' . $user->disabled_at . ' by ' . $adminUsername . ' (' . $adminUserId . '): ' . $reasonMessage . PHP_EOL;
            $user->save();
        }
    }

    public static function deleteOne(int $id)
    {
        $config = \Fuppi\App::getInstance()->getConfig();
        if ($deletedUser = self::getOne($id)) {
            $userPermissions = UserPermission::getUserPermissions($deletedUser);
            $uploadedFiles = UploadedFile::getAllByUser($deletedUser);
            if (parent::deleteOne($id)) {
                foreach ($uploadedFiles as $uploadedFile) {
                    UploadedFile::deleteOne($uploadedFile->uploaded_file_id);
                }
                unlink_recursive($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $deletedUser->username);
                foreach ($userPermissions as $userPermission) {
                    UserPermission::deleteOne($userPermission->user_permission_id);
                }
            }
        }
    }

    public function getUploadedFiles()
    {
        return UploadedFile::getAllByUser($this);
    }

    public function hasPermission(string $permissionName)
    {
        return UserPermission::isUserPermitted($permissionName, $this);
    }

    public function addPermission(string $permissionName)
    {
        if ($this->user_id < 1) {
            throw new \Exception('User must be saved before adding permission');
        }
        if (!$this->hasPermission($permissionName)) {
            $userPermission = new UserPermission();
            $userPermission->user_id = $this->user_id;
            $userPermission->permission_name = $permissionName;
            $userPermission->permission_value = true;
            $userPermission->save();
        }
    }

    public function deletePermission(string $permissionName)
    {
        if ($this->hasPermission($permissionName)) {
            if ($userPermission = UserPermission::getUserPermission($_POST['permissionName'], $this)) {
                UserPermission::deleteOne($userPermission->user_permission_id);
            }
        }
    }
}
