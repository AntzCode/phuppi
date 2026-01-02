<?php

/**
 * FileLogger.php
 *
 * FileLogger class for logging messages to a file in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi;

use Flight;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerTrait;

class FileLogger extends AbstractLogger
{
    use LoggerTrait;

    /** @var string Path to the log file */
    private $logFile;

    /**
     * Constructor to initialize the log file path and create directory if needed.
     */
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
     * @param mixed $level The log level
     * @param string|\Stringable $message The message to log
     * @param array $context Context data for interpolation
     * @return void
     * @throws \Psr\Log\InvalidArgumentException
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = $this->interpolate($message, $context);
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Interpolates context values into the message string.
     *
     * @param mixed $message The message string or object
     * @param array $context Array of context key-value pairs
     * @return string The interpolated message
     */
    private function interpolate($message, array $context = []): string
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
