<?php

namespace Fuppi;

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'User.php');

use Fuppi\User;

class App
{
    protected \Fuppi\User $user;
    protected \Fuppi\Config $config;
    protected \Fuppi\Db $db;

    protected static array $instances = [];
    protected function __construct()
    {
    }

    protected function init()
    {
        $this->config = Config::getInstance();
        $this->db = new Db();
        $this->user = new User($_SESSION['\Fuppi\App.user'] ?? []);
    }

    public function __destruct()
    {
        $_SESSION['\Fuppi\App.user'] = $this->user->getData();
    }

    public static function getInstance($namespace = "Fuppi")
    {
        if (!array_key_exists($namespace, self::$instances)) {
            self::$instances[$namespace] = new self();
            self::$instances[$namespace]->init();
        }
        return self::$instances[$namespace];
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getDb(): Db
    {
        return $this->db;
    }
}
