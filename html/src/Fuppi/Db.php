<?php

namespace Fuppi;

use Exception;
use PDO;

class Db
{
    protected PDO $pdo;

    public function __construct(string $pdoConnectionString = null)
    {
        $config = Config::getInstance();

        if (empty($pdoConnectionString)) {

            if (!file_exists($config->sqlite3_file_path)) {
                throw new Exception('Trying to open database that does not exist, make sure you have installed fuppi correctly');
            }

            $pdoConnectionString = 'sqlite:' . $config->sqlite3_file_path;
        }

        $this->pdo = new PDO($pdoConnectionString);
    }

    public function getPdo()
    {
        return $this->pdo;
    }
}
