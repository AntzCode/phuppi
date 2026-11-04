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

use Flight;

class S3Storage implements StorageInterface
{
    /** @var S3Service */
    private S3Service $client;

    /** @var string */
    private string $bucket;

    /** @var string */
    private string $pathPrefix;

    /**
     * Constructor for S3Storage.
     *
     * @param array $config Configuration array with bucket, path_prefix, region, key, secret, endpoint, public_endpoint (optional: the address browsers use for presigned URLs).
     */
    public function __construct(array $config)
    {
        $this->bucket = $config['bucket'] ?? '';
        $this->pathPrefix = $config['path_prefix'] ?? '';
        $region = $config['region'] ?? 'us-east-1';
        $key = $config['key'] ?? '';
        $secret = $config['secret'] ?? '';
        $endpoint = $config['endpoint'] ?? null;

        // Check if this is a Digital Ocean Spaces endpoint
        $isDigitalOcean = $endpoint && strpos($endpoint, 'digitaloceanspaces.com') !== false;

        // For DO Spaces, ensure the endpoint doesn't include the bucket name
        // Users might enter "bucket.region.digitaloceanspaces.com" but we need just "region.digitaloceanspaces.com"
        if ($isDigitalOcean && $endpoint && $this->bucket) {
            $bucketPrefix = $this->bucket . '.';
            if (strpos($endpoint, $bucketPrefix) === 0) {
                $endpoint = substr($endpoint, strlen($bucketPrefix));
            }
        }

        $this->client = new S3Service([
            'region' => $region,
            'key' => $key,
            'secret' => $secret,
            'endpoint' => $endpoint,
            'public_endpoint' => $config['public_endpoint'] ?? null,
            'bucket' => $this->bucket,
            // DO Spaces uses virtual-hosted style URLs, not path-style
            'path_style' => !$isDigitalOcean,
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
            $this->client->putObject($key, $sourcePath);
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
        return $this->client->getObject($key)['Body'];
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
        return $this->client->doesObjectExist($key);
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
            $this->client->deleteObject($key);
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
        $result = $this->client->headObject($key);
        return $result === null ? null : $result['ContentLength'];
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

        // Extra parameters use the SDK-style names, e.g. ['ResponseContentDisposition' => 'attachment']
        $query = [];
        foreach ($extraParams as $name => $value) {
            $query[strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name))] = $value;
        }

        return $this->client->presignUrl('GET', $key, (int) $expiresIn, $query);
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
            // The content type is not signed, so the browser may send any Content-Type header
            return $this->client->presignUrl('PUT', $key, $expiresIn);
        } catch (\Exception $e) {
            Flight::logger()->error('S3Storage getPresignedPutUrl: Failed to create presigned URL for ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Tests the connection to S3.
     *
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function testConnection(): array
    {
        try {
            $this->client->headBucket();
            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            Flight::logger()->error('S3Storage testConnection: Failed to connect to ' . $this->bucket . ': ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
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
     * @return S3Service The S3 client.
     */
    public function getClient(): S3Service
    {
        return $this->client;
    }
}
