<?php

namespace Fuppi;

class ApiResponse
{
    const STATUS_OK = 200;
    const STATUS_ERROR = 500;
    const STATUS_NOT_FOUND = 404;
    protected string $format = 'json';
    protected int $status = 200;
    protected string $message = '';
    protected $data = null;

    public function throwException(string $message, $data = null)
    {
        $this->message = $message;
        $this->status = self::STATUS_ERROR;
        $this->data = $data;
        fuppi_stop();
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
        echo $this->__toString();
        fuppi_end('json');
        exit;
    }

    public function throwNotFound()
    {
        fuppi_stop();
        $sapi_type = php_sapi_name();
        if (substr($sapi_type, 0, 3) == 'cgi') {
            header("Status: 404 Not Found");
        } else {
            header("HTTP/1.1 404 Not Found");
        }
        exit;
    }

    public function sendResponse()
    {
        fuppi_stop();
        echo $this->__toString();
        fuppi_end('json');
        exit;
    }

    public function __set($name, $value)
    {
        switch ($name) {
            case 'format':
                if (in_array($value, ['json'])) {
                    $this->status = $value;
                }
                break;
            case 'status':
                if (in_array($value, [self::STATUS_OK, self::STATUS_NOT_FOUND, self::STATUS_ERROR])) {
                    $this->status = $value;
                }
                break;
            case 'message':
            case 'data':
                $this->$name = $value;
                break;
        }
    }

    public function __get($name)
    {
        if (in_array($name, ['status', 'message', 'data'])) {
            return $this->$name;
        }
    }

    public function __toString()
    {
        switch ($this->format) {
            case 'json':
                if ($this->status === self::STATUS_OK) {
                    return json_encode($this->data);
                } else {
                    return json_encode([
                        'status' => $this->status,
                        'message' => $this->message,
                        'data' => $this->data
                    ]);
                }
                break;
        }
    }
}
