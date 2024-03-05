<?php

namespace Fuppi;

use Fuppi\Abstract\Model;

class UploadedFile extends Model
{

    protected static string $tablename = 'fuppi_uploaded_files';
    protected static string $primaryKeyColumnName = 'file_id';

    protected $data = [
        'file_id' => 0,
        'user_id' => 0,
        'filename' => '',
        'filesize' => 0,
        'mimetype' => '',
        'extension' => '',
        'uploaded_at' => ''
    ];

    protected User $user;

    public static function getOne(int $id): ?UploadedFile
    {
        $uploadedFile = new self();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($uploadedFile->getData())) . '` FROM `' . self::$tablename . '` WHERE `' . self::$primaryKeyColumnName . '` = :id');
        if ($statement->execute(['id' => $id])) {
            $data = $statement->fetch();
            if ($data) {
                $uploadedFile->setData($data);
                return $uploadedFile;
            }
        }
    }

    public static function deleteOne($fileId)
    {
        $config = \Fuppi\App::getInstance()->getConfig();
        $db = \Fuppi\App::getInstance()->getDb();
        if ($uploadedFile = UploadedFile::getOne($fileId)) {
            $fileUser = $uploadedFile->getUser();
            $statement = $db->getPdo()->query('DELETE  FROM `' . self::$tablename . '` WHERE `' . self::$primaryKeyColumnName . '` = :id');
            if ($statement->execute(['id' => $fileId])) {
                if (file_exists($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $fileUser->username . DIRECTORY_SEPARATOR . $uploadedFile->filename)) {
                    unlink($config->uploadedFilesPath . DIRECTORY_SEPARATOR . $fileUser->username . DIRECTORY_SEPARATOR . $uploadedFile->filename);
                }
            }
        }
    }

    public function getUser(): ?User
    {
        if ($this->user_id > 0) {
            if (!isset($this->user) || $this->user->user_id !== $this->user_id) {
                $this->user = User::getOne($this->user_id);
            }
            return $this->user;
        }
    }
}
