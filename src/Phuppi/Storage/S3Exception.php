<?php

/**
 * S3Exception.php
 *
 * Exception thrown by S3Service when a request to an S3-compatible server fails.
 *
 * @package Phuppi\Storage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.7.0
 */

namespace Phuppi\Storage;

class S3Exception extends \RuntimeException
{
    /** @var int HTTP status code of the response, or 0 if no response was received */
    private int $statusCode;

    /** @var string|null S3 error code from the response body, e.g. NoSuchKey */
    private ?string $s3Code;

    public function __construct(string $message, int $statusCode = 0, ?string $s3Code = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->s3Code = $s3Code;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getS3Code(): ?string
    {
        return $this->s3Code;
    }
}
