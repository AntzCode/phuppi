<?php

namespace Fuppi;

class Config
{
    protected array $fuppiConfig = [];

    protected static array $instances = [];

    protected function __construct(array $fuppiConfig)
    {
        foreach ($fuppiConfig as $keyname => $value) {
            $this->fuppiConfig[$keyname] = $value;
        }
    }

    public function __get(string $keyname)
    {
        if (array_key_exists($keyname, $this->fuppiConfig)) {
            return $this->fuppiConfig[$keyname];
        }
    }

    public static function getInstance(array $fuppiConfig = [])
    {
        if (empty($fuppiConfig)) {
            require(FUPPI_APP_PATH . DIRECTORY_SEPARATOR . 'config.php');
        }
        $index = md5(json_encode($fuppiConfig));
        if (!($instance = self::$instances[$index] ?? null) || !($instance instanceof self)) {
            self::$instances[$index] = new self($fuppiConfig);
        }
        return self::$instances[$index];
    }
}
