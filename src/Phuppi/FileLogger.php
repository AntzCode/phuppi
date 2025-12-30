<?php

namespace Phuppi;

use Flight;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerTrait;

class FileLogger extends AbstractLogger
{
    private $logFile;
    use LoggerTrait;

    public function __construct()
    {
        $this->logFile = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'phuppi.log';
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     *
     * @throws \Psr\Log\InvalidArgumentException
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = $this->interpolate($message, $context);
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    private function interpolate($message, array $context = [])
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        return strtr($message, $replace);
    }
}
