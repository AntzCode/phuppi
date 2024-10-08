<?php

namespace Fuppi;

use \Fuppi\Abstract\HasUser;
use \Fuppi\Abstract\Model;
use \Fuppi\SearchQuery;
use PDO;

class UploadedFile extends Model
{
    use HasUser;

    protected string $_tablename = 'fuppi_uploaded_files';
    protected string $_primaryKeyColumnName = 'uploaded_file_id';
    protected string $_userColumnName = 'user_id';

    protected $tags = null;

    protected $data = [
        'uploaded_file_id' => 0,
        'user_id' => 0,
        'voucher_id' => 0,
        'filename' => '',
        'display_filename' => '',
        'filesize' => 0,
        'mimetype' => '',
        'extension' => '',
        'uploaded_at' => '',
        'notes' => ''
    ];

    protected User $user;

    public static function getOne(int $id) : ?UploadedFile
    {
        return parent::getOne($id);
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

    public static function sanitizeFilename(string $filename){
        return preg_replace('/[^a-zA-Z0-9\.\-_(),]/', '', str_replace(' ', '_', $filename));
    }

    public static function generateUniqueFilename(string $filename)
    {
        $db = \Fuppi\App::getInstance()->getDb();

        $strippedFilename = self::sanitizeFilename($filename);

        $sanitizedFilename = $strippedFilename;

        $statement = $db->getPdo()->prepare("SELECT COUNT(*) AS `tcount` FROM `fuppi_uploaded_files` WHERE `filename` = :filename");

        $iterations = 0;
        while($statement->execute(['filename' => $sanitizedFilename]) && $statement->fetch()['tcount'] > 0 && $iterations < 500){
            $sanitizedFilename = ($iterations < 1 ? $strippedFilename : pathinfo($strippedFilename, PATHINFO_FILENAME) . '(' . ($iterations * 1) . ').' . pathinfo($strippedFilename, PATHINFO_EXTENSION));
            $iterations++;
        }

        return $sanitizedFilename;
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

    public function getRemotePresignedUrl(string $action, $voucherId = null, int $minLifetime = 30)
    {
        if (in_array($action, ['GetObject'])) {
            $db = \Fuppi\App::getInstance()->getDb();
            $query = 'SELECT `url` FROM `fuppi_uploaded_files_remote_auth` WHERE `action` = :action AND `uploaded_file_id` = :uploaded_file_id AND `expires_at` >= :expires_at';
            $bindings = [
                'action' => $action,
                'uploaded_file_id' => $this->uploaded_file_id,
                'expires_at' => date('Y-m-d H:i:s', time() + $minLifetime)
            ];
            if (is_null($voucherId)) {
                $query .= ' AND `voucher_id` IS NULL ';
            } else {
                $query .= ' AND `voucher_id` = :voucher_id';
                $bindings['voucher_id'] = $voucherId;
            }
            $statement = $db->getPdo()->query($query);
            if ($statement->execute($bindings)) {
                $data = $statement->fetch(PDO::FETCH_ASSOC);
                if ($data) {
                    return $data['url'];
                }
            }
        }
    }

    public function setRemotePresignedUrl(string $url, string $action, int $expiresAt, $voucherId = null)
    {
        $db = \Fuppi\App::getInstance()->getDb();
        $query = "INSERT INTO `fuppi_uploaded_files_remote_auth` (`uploaded_file_id`, `voucher_id`, `action`, `url`, `expires_at`) VALUES (:uploaded_file_id, :voucher_id, :action, :url, :expires_at)";
        $bindings = [
            'uploaded_file_id' => $this->uploaded_file_id,
            'voucher_id' => $voucherId,
            'action' => $action,
            'url' => $url,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ];
        $statement = $db->getPdo()->prepare($query);
        if ($statement->execute($bindings)) {
            return true;
        } else {
            return false;
        }
    }

    public function dropAwsPresignedUrl()
    {
        $db = \Fuppi\App::getInstance()->getDb();
        $query = "DELETE FROM `fuppi_uploaded_files_remote_auth` WHERE `uploaded_file_id` = :uploaded_file_id";
        $bindings = [
            'uploaded_file_id' => $this->uploaded_file_id
        ];
        $statement = $db->getPdo()->prepare($query);
        if ($statement->execute($bindings)) {
            return true;
        } else {
            return false;
        }
    }

    public function getTags() : Array
    {
        if(is_array($this->tags)){
            return $this->tags;
        }
        $this->tags = [];
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare("SELECT `tag_id` FROM `fuppi_uploaded_files_tags` WHERE `uploaded_file_id` = :uploaded_file_id");
        if($statement->execute(['uploaded_file_id' => $this->uploaded_file_id])){
            while($row = $statement->fetch(PDO::FETCH_ASSOC)){
                $this->tags[] = Tag::getOne($row['tag_id']);
            }
        }
        return $this->tags;
    }

    public function hasTag(Tag $tag) : bool
    {
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare("SELECT COUNT(*) AS `tcount` FROM `fuppi_uploaded_files_tags` WHERE `uploaded_file_id` = :uploaded_file_id AND `tag_id` = :tag_id");
        if($statement->execute(['uploaded_file_id' => $this->uploaded_file_id, 'tag_id' => $tag->tag_id]) && $statement->fetch(PDO::FETCH_ASSOC)['tcount'] > 0){
            return true;
        }
        return false;
    }

    public function addTag(Tag $tag) : bool
    {
        $db = \Fuppi\App::getInstance()->getDb();
        if($this->hasTag($tag)){
            return true;
        } else {
            if($tag->tag_id > 0){
                $statement = $db->getPdo()->prepare("INSERT INTO `fuppi_uploaded_files_tags` (`uploaded_file_id`, `tag_id`) VALUES (:uploaded_file_id, :tag_id)");
                if($statement->execute(['uploaded_file_id' => $this->uploaded_file_id, 'tag_id' => $tag->tag_id])){
                    return true;
                }
            }
        }
        return false;
    }

    public function removeTag(Tag $tag) : bool
    {
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare("DELETE FROM `fuppi_uploaded_files_tags` WHERE `uploaded_file_id` = :uploaded_file_id AND `tag_id` = :tag_id");
        if($statement->execute(['uploaded_file_id' => $this->uploaded_file_id, 'tag_id' => $tag->tag_id])){
            return true;
        } else {
            return false;
        }
    }


    public static function getAllByTag(Tag $tag)
    {
        $db = \Fuppi\App::getInstance()->getDb();

        $statement = $db->getPdo()->query('SELECT uploaded_file_id FROM `fuppi_uploaded_files_tags` WHERE tag_id = :tag_id');
        $results = $statement->execute(['tag_id' => $tag->tag_id]);

        $uploadedFileIds = [];

        if ($results) {
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $data) {
                $uploadedFileIds[] = $data['uploaded_file_id'];
            }
        }

        return self::getAll($uploadedFileIds);
    }


}
