<?php

/**
 * S3Storage.php
 *
 * S3Storage class for managing file storage in the Phuppi application.
 *
 * @package Phuppi\Storage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Storage;

use Aws\S3\S3Client;
use Flight;

class S3Storage implements StorageInterface
{
    /** @var S3Client */
    private S3Client $s3Client;

    /** @var string */
    private string $bucket;

    /** @var string */
    private string $pathPrefix;

    /**
     * Constructor for S3Storage.
     *
     * @param array $config Configuration array with bucket, path_prefix, region, key, secret, endpoint.
     */
    public function __construct(array $config)
    {
        $this->bucket = $config['bucket'] ?? '';
        $this->pathPrefix = $config['path_prefix'] ?? '';
        $region = $config['region'] ?? 'us-east-1';
        $key = $config['key'] ?? '';
        $secret = $config['secret'] ?? '';
        $endpoint = $config['endpoint'] ?? null;

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
        ]);
    }

    /**
     * Gets the relative path for a filename.
     *
     * @param string $filename The filename.
     * @return string The relative path.
     */
    public function getRelativePath(string $filename): string
    {
        return $this->pathPrefix ? $this->pathPrefix . '/' . $filename : $filename;
    }

    /**
     * Uploads a file to S3.
     *
     * @param string $filename The filename.
     * @param string $sourcePath The source file path.
     * @return bool True if uploaded successfully, false otherwise.
     */
    public function put(string $filename, string $sourcePath): bool
    {
        $key = $this->getRelativePath($filename);
        try {
            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $sourcePath,
            ]);
            Flight::logger()->info('S3Storage put: Successfully uploaded ' . $key);
            return true;
        } catch (\Exception $e) {
            Flight::logger()->error('S3Storage put: Failed to upload ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets a stream for the file.
     *
     * @param string $filename The filename.
     * @return mixed The stream.
     */
    public function getStream(string $filename): mixed
    {
        $key = $this->getRelativePath($filename);
        $result = $this->s3Client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        return $result['Body'];
    }

    /**
     * Checks if a file exists.
     *
     * @param string $filename The filename.
     * @return bool True if exists, false otherwise.
     */
    public function exists(string $filename): bool
    {
        $key = $this->getRelativePath($filename);
        return $this->s3Client->doesObjectExist($this->bucket, $key);
    }

    /**
     * Deletes a file from S3.
     *
     * @param string $filename The filename.
     * @return bool True if deleted successfully, false otherwise.
     */
    public function delete(string $filename): bool
    {
        $key = $this->getRelativePath($filename);
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            Flight::logger()->info('S3Storage delete: Successfully deleted ' . $key);
            return true;
        } catch (\Exception $e) {
            Flight::logger()->error('S3Storage delete: Failed to delete ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets the size of a file.
     *
     * @param string $filename The filename.
     * @return ?int The size in bytes or null if not found.
     */
    public function size(string $filename): ?int
    {
        $key = $this->getRelativePath($filename);
        $result = $this->s3Client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        return (int) $result['ContentLength'];
    }

    /**
     * Gets a presigned URL for the file.
     *
     * @param string $filename The filename.
     * @param int $expiresIn Expiration time in seconds.
     * @param array $extraParams Extra parameters.
     * @return ?string The URL or null if failed.
     */
    public function getUrl(string $filename, $expiresIn = 3600, $extraParams = []): ?string
    {
        $key = $this->getRelativePath($filename);

        $params = array_merge([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ], $extraParams);

        $command = $this->s3Client->getCommand('GetObject', $params);
        $request = $this->s3Client->createPresignedRequest($command, time() + $expiresIn);
        return (string) $request->getUri();
    }

    /**
     * Gets a presigned PUT URL for uploading.
     *
     * @param string $filename The filename.
     * @param string|null $contentType The content type.
     * @param int $expiresIn Expiration time in seconds.
     * @return ?string The URL or null if failed.
     */
    public function getPresignedPutUrl(string $filename, string | null $contentType = null, int $expiresIn = 3600): ?string
    {
        $key = $this->getRelativePath($filename);
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ];
            if ($contentType) {
                $params['ContentType'] = $contentType;
            }
            $command = $this->s3Client->getCommand('PutObject', $params);
            $request = $this->s3Client->createPresignedRequest($command, time() + $expiresIn);
            return (string) $request->getUri();
        } catch (\Exception $e) {
            Flight::logger()->error('S3Storage getPresignedPutUrl: Failed to create presigned URL for ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Tests the connection to S3.
     *
     * @return bool True if connected, false otherwise.
     */
    public function testConnection(): bool
    {
        try {
            $this->s3Client->headBucket(['Bucket' => $this->bucket]);
            return true;
        } catch (\Exception $e) {
            Flight::logger()->error('S3Storage testConnection: Failed to connect to ' . $this->bucket . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets the bucket name.
     *
     * @return string The bucket name.
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * Gets the path prefix.
     *
     * @return string The path prefix.
     */
    public function getPathPrefix(): string
    {
        return $this->pathPrefix;
    }

    /**
     * Gets the S3 client.
     *
     * @return S3Client The S3 client.
     */
    public function getS3Client(): S3Client
    {
        return $this->s3Client;
    }
}
