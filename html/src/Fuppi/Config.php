<?php

namespace Fuppi;

use PDO;

class Config
{
    protected array $fuppiConfig = [];
    protected static array $instances = [];
    protected $settings = null;

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

    public function getDefaultSettings()
    {
        require(FUPPI_APP_PATH . DIRECTORY_SEPARATOR . 'config.php');
        return $fuppiConfig['settings'];
    }

    public function getSetting(string $name = null)
    {
        if (is_null($this->settings)) {
            $db = \Fuppi\App::getInstance()->getDb();
            $statement = $db->getPdo()->query("SELECT * FROM `fuppi_settings`");
            $statement->execute();
            $this->settings = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $data) {
                $this->settings[$data['name']] = $data['value'];
            }
        }
        if ($name === null) {
            return $this->settings;
        } else {
            return array_key_exists($name, $this->settings) ? $this->settings[$name] : null;
        }
    }

    public function setSetting($name, $value)
    {
        $this->settings[$name] = $value;
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare("INSERT OR REPLACE INTO `fuppi_settings` (`name`, `value`) values (:name, :value)");
        $statement->execute([
            'name' => $name,
            'value' => $value
        ]);
    }
}
