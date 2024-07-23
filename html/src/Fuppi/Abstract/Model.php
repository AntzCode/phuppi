<?php

namespace Fuppi\Abstract;

use Fuppi\SearchQuery;

use PDO;

abstract class Model
{
    protected string $_tablename;
    protected string $_primaryKeyColumnName;

    protected $data = [];
    protected $jsonColumnNames = [];

    public function __construct()
    {
    }

    public function save(): bool
    {
        $db = \Fuppi\App::getInstance()->getDb();

        if ($this->data[$this->_primaryKeyColumnName] > 0) {
            $sets = [];
            foreach (array_keys($this->data) as $keyname) {
                $sets[] = '`' . $keyname . '` = :' . $keyname;
            }
            $statement = $db->getPdo()->query('UPDATE `' . $this->_tablename . '` SET ' . implode(', ', $sets) . ' WHERE `' . $this->_primaryKeyColumnName . '` = :' . $this->_primaryKeyColumnName);
            return $statement->execute($this->toDb($this->data));
        } else {

            $insertKeys = array_diff(array_keys($this->getData()), [$this->_primaryKeyColumnName]);
            $insertValues = $this->toDb(array_diff_key($this->getData(), [$this->_primaryKeyColumnName => null]));
            $statement = $db->getPdo()->prepare('INSERT INTO `' . $this->_tablename . '` (`' . implode('`, `', $insertKeys) . '`) VALUES  (:' . implode(', :', $insertKeys) . ')');
            if ($statement->execute($insertValues)) {
                $this->data[$this->_primaryKeyColumnName] = $db->getPdo()->lastInsertId();
                return true;
            } else {
                return false;
            }
        }
    }

    public static function getOne(int $id): ?self
    {
        $className = get_called_class();
        $instance = new $className();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `' . $instance->_primaryKeyColumnName . '` = :id');
        if ($statement->execute(['id' => $id])) {
            $data = $statement->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                $instance->setData($instance->fromDb($data));
            }
            return $instance;
        }
        return null;
    }

    public static function getAll(array $ids = null): array
    {
        $className = get_called_class();
        $instance = new $className();
        $userPermissions = [];
        $db = \Fuppi\App::getInstance()->getDb();
        if (!is_null($ids)) {
            $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `' . $instance->_primaryKeyColumnName . '` IN :ids');
            $results = $statement->execute(['ids' => $ids]);
        } else {
            $statement = $db->getPdo()->query('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '`');
            $results = $statement->execute();
        }
        if ($results) {
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $userPermissionData) {
                $userPermission = new $className();
                $userPermission->setData($userPermission->fromDb($userPermissionData));
                $userPermissions[] = $userPermission;
            }
        }
        return $userPermissions;
    }

    public static function search(SearchQuery $condition=null){
        $className = get_called_class();

        $instance = new $className();
        $db = \Fuppi\App::getInstance()->getDb();
        
        $rows = [];

        $condition->setTablename($instance->_tablename)->setColumnNames(array_keys($instance->getData()));
        
        list($rowsQuery, $rowsBindings) = $condition->getQuery();
        list($countQuery, $countBindings) = $condition->getQuery('COUNT(*) AS totalCount', 0, 0);

        $rowsStatement = $db->getPdo()->query($rowsQuery);
        $rowsResults = $rowsStatement->execute($rowsBindings);

        if ($rowsResults) {
            foreach ($rowsStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rowInstance = new $className();
                $rowInstance->setData($rowInstance->fromDb($row));
                $rows[] = $rowInstance;
            }
        }

        $countStatement = $db->getPdo()->query($countQuery);
        $countResults = $countStatement->execute($countBindings);
        $totalCount = 0;

        if ($countResults){
            foreach ($countStatement->fetchAll(PDO::FETCH_ASSOC) as $data) {
                $totalCount = $data['totalCount'];
            }
        }

        return [
            'count' => $totalCount,
            'rows' => $rows
        ];

    }

    public static function deleteOne(int $id)
    {
        $className = get_called_class();
        $instance = new $className();
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->query('DELETE  FROM `' . $instance->_tablename . '` WHERE `' . $instance->_primaryKeyColumnName . '` = :id');
        return $statement->execute(['id' => $id]);
    }

    public function getData()
    {
        return $this->fromDb($this->data);
    }

    public function setData(array $data)
    {
        $newData = $this->toDb($data);
        foreach ($newData as $k => $v) {
            $this->data[$k] = $v;
        }
    }

    public function __set($keyname, $value)
    {
        if (array_key_exists($keyname, $this->data)) {
            $this->setData([$keyname => $value]);
        }
    }

    public function __get($keyname)
    {
        if (array_key_exists($keyname, $this->data)) {
            return $this->getData()[$keyname];
        }
    }

    protected function toDb($data)
    {
        if (is_array($data)) {
            $newData = [];
            $className = get_called_class();
            $jsonColumnNames = (new $className())->jsonColumnNames ?? [];
            foreach ($data as $keyname => $value) {
                if (in_array($keyname, $jsonColumnNames)) {
                    $newData[$keyname] = json_encode($value);
                } else {
                    $newData[$keyname] = $value;
                }
            }
            return $newData;
        } else {
            return $data;
        }
    }

    protected function fromDb($data)
    {
        if (is_array($data)) {
            $newData = [];
            $className = get_called_class();
            $jsonColumnNames = (new $className())->jsonColumnNames ?? [];
            foreach ($data as $keyname => $value) {
                if (in_array($keyname, $jsonColumnNames)) {
                    $newData[$keyname] = json_decode($value);
                } else {
                    $newData[$keyname] = $value;
                }
            }
            return $newData;
        } else {
            return $data;
        }
    }
}
