<?php

namespace Fuppi;

use Fuppi\Abstract\HasUser;
use Fuppi\Abstract\Model;
use PDO;

class Note extends Model
{
    use HasUser;

    protected string $_tablename = 'fuppi_notes';
    protected string $_primaryKeyColumnName = 'note_id';
    protected string $_userColumnName = 'user_id';

    protected $data = [
        'note_id' => 0,
        'user_id' => 0,
        'voucher_id' => 0,
        'filename' => '',
        'content' => '',
        'created_at' => '',
        'updated_at' => ''
    ];

    protected User $user;

    public static function getOne(int $id): ?Note
    {
        return parent::getOne($id);
    }

    public static function getAllByUser(User $user)
    {
        $instance = new self();
        $uploadedFiles = [];
        $db = \Fuppi\App::getInstance()->getDb();

        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `user_id` = :user_id  ORDER BY `created_at` DESC');
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

    public static function generateUniqueFilename(string $filename)
    {

        $db = \Fuppi\App::getInstance()->getDb();

        $strippedFilename = preg_replace('/[^a-zA-Z0-9\.\-_(),]/', '', str_replace(' ', '_', $filename));

        $iterations = 0;
        do {
            $sanitizedFilename = ($iterations < 1 ? $strippedFilename : pathinfo($strippedFilename, PATHINFO_FILENAME) . '(' . ($iterations * 1) . ').' . pathinfo($strippedFilename, PATHINFO_EXTENSION));
            $iterations++;
            $statement = $db->getPdo()->prepare("SELECT COUNT(*) AS `tcount` FROM `fuppi_notes` WHERE `filename` = :filename");
        } while ($statement->execute(['filename' => $sanitizedFilename]) && $statement->fetch()['tcount'] > 0 && $iterations < 500);

        return $sanitizedFilename;
    }

    public function createToken(int $lifetimeSeconds, int $voucherId = null)
    {
        if ($lifetimeSeconds > 0) {
            $expiresTime = time() + $lifetimeSeconds;
            $token = md5($this->note_id . $expiresTime . rand());
            $db = \Fuppi\App::getInstance()->getDb();

            $statement = $db->getPdo()->prepare('INSERT INTO `fuppi_note_tokens` (`note_id`, `voucher_id`, `token`, `created_at`, `expires_at`) VALUES (:note_id, :voucher_id, :token, :created_at, :expires_at)');

            $results = $statement->execute([
                'note_id' => $this->note_id,
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
        $statement = $db->getPdo()->query('SELECT `expires_at` FROM `fuppi_note_tokens` WHERE `token` = :token and `note_id` = :note_id');
        if ($statement->execute(['token' => $token, 'note_id' => $this->note_id])) {
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
