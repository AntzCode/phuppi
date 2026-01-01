<?php

namespace Phuppi\Storage;

use Flight;
use Aws\S3\S3Client;

class S3Storage implements StorageInterface
{
    private S3Client $s3Client;
    private string $bucket;
    private string $pathPrefix;

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

    public function getRelativePath(string $filename): string
    {
        return $this->pathPrefix ? $this->pathPrefix . '/' . $filename : $filename;
    }

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

    public function getStream(string $filename)
    {
        $key = $this->getRelativePath($filename);
        $result = $this->s3Client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        return $result['Body'];
    }

    public function exists(string $filename): bool
    {
        $key = $this->getRelativePath($filename);
        return $this->s3Client->doesObjectExist($this->bucket, $key);
    }

    public function delete(string $filename): bool
    {
        $key = $this->getRelativePath($filename);
        $this->s3Client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        return true;
    }

    public function size(string $filename): ?int
    {
        $key = $this->getRelativePath($filename);
        $result = $this->s3Client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        return (int) $result['ContentLength'];
    }

    public function getUrl(string $filename, $expiresIn = 3600, $extraParams=[]): ?string
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

    public function getPresignedPutUrl(string $filename, string $contentType = null, int $expiresIn = 3600): ?string
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
}