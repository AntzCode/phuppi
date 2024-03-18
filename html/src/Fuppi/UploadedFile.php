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

}
