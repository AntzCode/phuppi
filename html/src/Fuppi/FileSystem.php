<?php

namespace Fuppi;

class FileSystem
{
    const SERVER_FILESYSTEM = 'server_filesystem';
    const AWS_S3 = 'aws_s3';
    const DIGITAL_OCEAN_SPACES = 'do_spaces';

    private static $instance = null;
    private $sdk = null;
    private $client = null;
    private $containerName = null;

    private function __construct()
    {
        $config = \Fuppi\App::getInstance()->getConfig();
        $this->containerName = $config->getSetting('remote_files_container');
    }

    public function isRemote($storageType=null) : bool
    {
        $config = \Fuppi\App::getInstance()->getConfig();
        if (!is_null($storageType)) {
            return $config->getSetting('file_storage_type') === $storageType;
        } else {
            return in_array($config->getSetting('file_storage_type'), [
                self::AWS_S3,
                self::DIGITAL_OCEAN_SPACES
            ]);
        }
    }

    public static function validateEndpoint()
    {
        $config = \Fuppi\App::getInstance()->getConfig();

        switch ($config->getSetting('file_storage_type')) {
            case self::AWS_S3:
                if (!preg_match('/\.amazonaws\.com$/', $config->getSetting('remote_files_endpoint'))) {
                    throw new \Exception('Configuration error: AWS endpoint must end with amazonaws.com');
                }
                break;
            case self::DIGITAL_OCEAN_SPACES:
                if (!preg_match('/\.digitaloceanspaces\.com$/', $config->getSetting('remote_files_endpoint'))) {
                    throw new \Exception('Configuration error: Digital Ocean Spaces endpoint must end with digitaloceanspaces.com');
                }
                break;
        }
    }

    public static function isValidRemoteEndpoint() : bool
    {
        try {
            self::validateEndpoint();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getSdk() : \Aws\Sdk
    {
        // security: do not expose credentials to competitive cloud providers
        self::validateEndpoint();

        if (is_null($this->sdk)) {
            $config = \Fuppi\App::getInstance()->getConfig();

            $this->sdk = new \Aws\Sdk([
                'region' => $config->getSetting('remote_files_region'),
                'endpoint' => $config->getSetting('remote_files_endpoint'),
                'use_path_style_endpoint' => (($config->getSetting('file_storage_type') === self::DIGITAL_OCEAN_SPACES) ? false : true),
                'credentials' =>  [
                    'key'    => $config->getSetting('remote_files_access_key'),
                    'secret' => $config->getSetting('remote_files_secret')
                ]
            ]);
        }
        return $this->sdk;
    }

    public function getClient() : \Aws\AwsClient
    {
        if (is_null($this->client)) {
            $this->client = $this->getSdk()->createS3();
        }
        return $this->client;
    }

    public function getRemoteUrl($objectPath)
    {
        return 's3://' . $this->containerName . '/' . $objectPath;
    }

    public function getObjectMetaData($objectPath)
    {
        $config = \Fuppi\App::getInstance()->getConfig();
        return $this->getClient()->headObject([
            'Bucket' => $this->containerName,
            'Key' => $objectPath
        ]);
    }

    public function putObject($objectKey, $sourceFilePath)
    {
        return $this->getClient()->putObject([
            'Bucket' => $this->containerName,
            'Key' => $objectKey,
            'SourceFile' => $sourceFilePath
        ]);
    }

    public function deleteObject($keyname)
    {
        return $this->getClient()->deleteObject([
            'Bucket' => $this->containerName,
            'Key' => $keyname
        ]);
    }

    public function createPresignedRequest($objectKey, $extra=[], $expiresAt=null, $command="GetObject")
    {
        $config = \Fuppi\App::getInstance()->getConfig();
        $params = [
            'Bucket' => $this->containerName,
            'Key' => $objectKey
        ];
        foreach ($extra as $key=>$value) {
            $params[$key] = $value;
        }
        $cmd = $this->getClient()->getCommand($command, $params);
        if (is_null($expiresAt)) {
            $expiresAt =  time() + (int) $config->getSetting('remote_files_token_lifetime_seconds');
        }

        return $this->getClient()->createPresignedRequest($cmd, $expiresAt);
    }
}
