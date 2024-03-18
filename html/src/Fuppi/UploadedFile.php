<?php

namespace Fuppi;

use Fuppi\Abstract\HasUser;
use Fuppi\Abstract\Model;
use PDO;

class UploadedFile extends Model
{
    use HasUser;

    protected string $_tablename = 'fuppi_uploaded_files';
    protected string $_primaryKeyColumnName = 'uploaded_file_id';
    protected string $_userColumnName = 'user_id';

    protected $data = [
        'uploaded_file_id' => 0,
        'user_id' => 0,
        'voucher_id' => 0,
        'filename' => '',
        'filesize' => 0,
        'mimetype' => '',
        'extension' => '',
        'uploaded_at' => ''
    ];

    protected User $user;

    public static function getOne(int $id): ?UploadedFile
    {
        return parent::getOne($id);
    }

    public static function deleteOne(int $id)
    {
        $config = \Fuppi\App::getInstance()->getConfig();
        if ($uploadedFile = UploadedFile::getOne($id)) {
            $fileUser = $uploadedFile->getUser();
            if (parent::deleteOne($id)) {
                if (file_exists($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $fileUser->username . DIRECTORY_SEPARATOR . $uploadedFile->filename)) {
                    unlink($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $fileUser->username . DIRECTORY_SEPARATOR . $uploadedFile->filename);
                }
            }
        }
    }

    public static function getAllByUser(User $user)
    {
        $instance = new self();
        $uploadedFiles = [];
        $db = \Fuppi\App::getInstance()->getDb();

        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `user_id` = :user_id  ORDER BY `uploaded_at` DESC');
        $results = $statement->execute(['user_id' => $user->user_id]);

        if ($results) {
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $data) {
                $uploadedFile = new self();
                $uploadedFile->setData($uploadedFile->fromDb($data));
                $uploadedFiles[] = $uploadedFile;
            }
        }
        return $uploadedFiles;
    }

    public function createToken(int $lifetimeSeconds, int $voucherId = null)
    {
        if ($lifetimeSeconds > 0) {
            $expiresTime = time() + $lifetimeSeconds;
            $token = md5($this->uploaded_file_id . $expiresTime . rand());
            $db = \Fuppi\App::getInstance()->getDb();

            $statement = $db->getPdo()->prepare('INSERT INTO `fuppi_uploaded_file_tokens` (`uploaded_file_id`, `voucher_id`, `token`, `created_at`, `expires_at`) VALUES (:uploaded_file_id, :voucher_id, :token, :created_at, :expires_at)');

            $results = $statement->execute([
                'uploaded_file_id' => $this->uploaded_file_id,
                'voucher_id' => $voucherId,
                'token' => $token,
                'created_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', $expiresTime)
            ]);

            return $token;
        }
    }

    public function getTokenExpiresAt(string $token)
    {
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('SELECT `expires_at` FROM `fuppi_uploaded_file_tokens` WHERE `token` = :token and `uploaded_file_id` = :uploaded_file_id');
        if ($statement->execute(['token' => $token, 'uploaded_file_id' => $this->uploaded_file_id])) {
            $data = $statement->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                return $data['expires_at'];
            }
        }
    }

    public function isValidToken(string $token)
    {
        if ($expiresAt = $this->getTokenExpiresAt($token)) {
            return strtotime($expiresAt) > time();
        }
        return false;
    }
}
