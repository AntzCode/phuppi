<?php

namespace Fuppi;

class SearchQuery
{
    protected $tableName = '';
    protected $columns = [];
    protected $operator = 'AND';
    protected $conditions = [];
    protected $orderBy = [];
    protected $limit = 20;
    protected $offset = 0;

    public function __construct($tableName=null, $columns=[])
    {
        $this->tableName = $tableName;
        $this->columns = $columns;
    }

    public function where(string $what, mixed $where, string $operator="eq")
    {
        switch($operator) {
            case 'eq':
                $this->conditions[] = [
                    'what' => $what,
                    'op' => $operator,
                    'where' => $where
                ];
                break;
        }
        return $this;
    }

    public function orderBy(string $column, $direction="ASC")
    {
        $this->orderBy = [$column, $direction];
        return $this;
    }

    public function limit(int $limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset)
    {
        $this->offset = $offset;
        return $this;
    }

    public function setOperator($operator)
    {
        $this->operator = $operator;
        return $this;
    }

    public function setColumnNames(array $columns)
    {
        $this->columns = $columns;
        return $this;
    }

    public function setTableName(string $tableName)
    {
        $this->tableName = $tableName;
        return $this;
    }

    public function chain(SearchQuery $condition)
    {
        $this->conditions[] = $condition;
        return $this;
    }

    public function getQuery($what=null, $limit=null, $offset=null)
    {
        if (is_null($this->tableName)) {
            throw new Exception('Attempt to call getQuery before setTableName()');
        }

        $query = '';

        list($conditions, $bindings) = $this->prepare();

        if (is_null($what)) {
            if (count($this->columns) > 0) {
                $what = '`' . implode('`, `', $this->columns) . '`';
            } else {
                $what = '*';
            }
        }

        $query = 'SELECT ' . $what . ' FROM ' . $this->tableName;

        if (strlen($conditions) > 0) {
            $query .= ' WHERE ' . $conditions;
        }

        if (count($this->orderBy) > 0) {
            $query .= ' ORDER BY `' . $this->orderBy[0] . '` ' . $this->orderBy[1];
        }

        if (!is_null($limit)) {
            if ($limit > 0) {
                $query .= " LIMIT $limit ";
            }
        } else {
            if (!is_null($this->limit)) {
                $query .= " LIMIT $this->limit ";
            }
        }

        if (!is_null($offset)) {
            if ($offset > 0) {
                $query .= " OFFSET $offset ";
            }
        } else {
            if (!is_null($this->offset)) {
                $query .= " OFFSET $this->offset ";
            }
        }

        return [$query, $bindings];
    }

    /**
     * @param string "AND" | "OR"
     */
    public function prepare($bindingPrefix='cond_')
    {
        $outputs = [];
        $bindings = [];

        foreach ($this->conditions as $condition) {
            if ($condition instanceof SearchQuery) {
                list($_conditions, $_bindings) = $condition->prepare($bindingPrefix . 'cond_');
                $iteration = count($outputs);
                $outputs[$iteration] = $_conditions;
                $bindings = array_merge($bindings, $_bindings);
            } else {
                switch($condition['op']) {
                    case 'eq':
                        $iteration = count($outputs);
                        $outputs[$iteration] = '`' . $condition['what'] . '`' . ' = :' . $bindingPrefix . $iteration;
                        $bindings[$bindingPrefix . $iteration] = $condition['where'];
                        break;
                }
            }
        }
        return [
            implode(' ' . $this->operator . ' ', $outputs),
            $bindings
        ];
    }
}
