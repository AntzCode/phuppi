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
    protected $settings = [];

    protected $data = [
        'user_id' => 0,
        'username' => '',
        'password' => '',
        'created_at' => '',
        'updated_at' => '',
        'disabled_at' => null,
        'session_expires_at' => null,
        'notes' => ''
    ];

    public static function getUsername(int $userId, $default = "")
    {
        return self::getOne($userId)?->username ?? $default;
    }

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

    public static function findBySessionId(string $sessionId)
    {
        $instance = new self();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare('SELECT `user_id`, `session_expires_at` FROM `fuppi_user_sessions` WHERE `session_id` = :session_id');
        if ($statement->execute(['session_id' => $sessionId]) && $row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if(strtotime($row['session_expires_at']) > time()){
                return self::getOne($row['user_id']);
            }else{
                $statement = $db->getPdo()->prepare('DELETE FROM `fuppi_user_sessions` WHERE `session_id` = :session_id');
                $statement->execute(['session_id' => $sessionId]);
            }
        }
        return null;
    }

    public function destroyPersistentCookie($sessionId){
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare('DELETE FROM `fuppi_user_sessions` WHERE `session_id` = :session_id');
        if ($statement->execute([
            'session_id' => $sessionId,
        ])) {
            return true;
        } else {
            return false;
        }
    }

    public function extendPersistentCookie($oldSessionId, $newSessionId, $expiresAt, $userAgent, $clientIp){
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare('INSERT INTO `fuppi_user_sessions` (`session_id`, `user_id`, `session_expires_at`, `last_login_at`, `user_agent`, `client_ip`) VALUES  (:session_id, :user_id, :expires_at, :last_login_at, :user_agent, :client_ip)');
        if ($statement->execute([
            'session_id' => $newSessionId,
            'user_id' => $this->user_id,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'user_agent' => $userAgent,
            'client_ip' => $clientIp,
            'last_login_at' => date('Y-m-d H:i:s')
        ])) {
            $statement = $db->getPdo()->query('DELETE  FROM `fuppi_user_sessions` WHERE `session_id` = :id');
            $statement->execute(['id' => $oldSessionId]);
        } else {
            return false;
        }
    }

    public function setPersistentCookie($sessionId, $expiresAt, $userAgent, $clientIp){
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare('INSERT INTO `fuppi_user_sessions` (`session_id`, `user_id`, `session_expires_at`, `last_login_at`, `user_agent`, `client_ip`) VALUES  (:session_id, :user_id, :expires_at, :last_login_at, :user_agent, :client_ip)');
        if ($statement->execute([
            'session_id' => $sessionId,
            'user_id' => $this->user_id,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'last_login_at' => date('Y-m-d H:i:s'),
            'user_agent' => $userAgent,
            'client_ip' => $clientIp
        ])) {
            return true;
        } else {
            return false;
        }
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
                unlink_recursive($config->uploaded_files_path . DIRECTORY_SEPARATOR . $deletedUser->username);
                foreach ($userPermissions as $userPermission) {
                    UserPermission::deleteOne($userPermission->user_permission_id);
                }
            }
        }
    }

    public function getNotes()
    {
        return Note::getAllByUser($this);
    }

    public function getUploadedFiles()
    {
        return UploadedFile::getAllByUser($this);
    }

    public function getSumUploadedFilesSize()
    {
        $sum = 0;
        foreach ($this->getUploadedFiles() as $uploadedFile) {
            $sum += (int) $uploadedFile->filesize;
        }
        return $sum;
    }

    public function hasPermission(string $permissionName)
    {
        if ($voucher = \Fuppi\App::getInstance()->getVoucher()) {
            return $voucher->hasPermission($permissionName);
        }
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
            if ($userPermission = UserPermission::getUserPermission($permissionName, $this)) {
                UserPermission::deleteOne($userPermission->user_permission_id);
            }
        }
    }

    public function setSetting($name, $value){
        $this->settings[$name] = $value;
    }

    public function getSetting($name){
        if(array_key_exists($name, $this->settings)){
            return $this->settings[$name];
        }
    }

    public function setSettings($settings){
        foreach($settings as $k => $v){
            $this->setSetting($k, $v);
        }
    }

    public function getSettings(){
        return $this->settings;
    }

}
