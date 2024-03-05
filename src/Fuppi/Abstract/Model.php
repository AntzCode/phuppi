<?php

namespace Fuppi\Abstract;

abstract class Model
{
    protected static string $tablename;
    protected static string $primaryKeyColumnName;

    protected $data = [];

    public function __construct(array $userData = [])
    {
        $this->setData($userData);
    }

    public static function getOne(int $id)
    {
    }

    public static function deleteOne(int $id)
    {
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData(array $data)
    {
        foreach ($data as $k => $v) {
            $this->data[$k] = $v;
        }
    }

    public function __set($keyname, $value)
    {
        if (array_key_exists($keyname, $this->data)) {
            $this->data[$keyname] = $value;
        }
    }

    public function __get($keyname)
    {
        if (array_key_exists($keyname, $this->getData())) {
            return $this->getData()[$keyname];
        }
    }
}
