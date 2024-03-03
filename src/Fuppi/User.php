<?php

namespace Fuppi;

require_once('App.php');

use App;

class User
{
    protected \Fuppi\App $app;
    protected \Fuppi\Db $db;
    protected int $user_id = 0;
    protected string $username = '';
    protected string $password = '';

    public function __construct(array $userData = [])
    {
        $this->setData($userData);
    }

    public function setData(array $userData = [])
    {
        foreach ($userData as $keyname => $value) {
            $this->$keyname = $value;
        }
    }

    public function getData()
    {
        return [
            'user_id' => $this->user_id,
            'username' => $this->username,
            'password' => $this->password
        ];
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getId()
    {
        return $this->user_id;
    }

    public function canUpload(): bool
    {
        return $this->user_id > 0;
    }
}
