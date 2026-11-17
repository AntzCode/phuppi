<?php

/**
 * S3Service.php
 *
 * A lean client for S3-compatible object storage (AWS S3, Digital Ocean Spaces, MinIO, Garage, ...).
 * Uses ext-curl and AWS Signature Version 4, and replaces the bundled AWS SDK.
 *
 * @package Phuppi\Storage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.7.0
 */

namespace Phuppi\Storage;

class S3Service
{
    private const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private string $scheme;
    private string $host;
    private ?int $port;
    private string $basePath;
    /** @var array|null Origin browsers use for presigned URLs when it differs from the one this server connects to */
    private ?array $publicOrigin = null;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private bool $pathStyle;

    /**
     * @param array $config Keys: bucket, region, key, secret, endpoint (null = AWS), public_endpoint (optional, see presignUrl()), path_style (bool, default true)
     */
    public function __construct(array $config)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP curl extension is required for S3 storage');
        }

        $this->bucket = (string) ($config['bucket'] ?? '');
        $this->region = (string) ($config['region'] ?? 'us-east-1') ?: 'us-east-1';
        $this->accessKey = (string) ($config['key'] ?? '');
        $this->secretKey = (string) ($config['secret'] ?? '');
        $this->pathStyle = (bool) ($config['path_style'] ?? true);

        $endpoint = $config['endpoint'] ?? null;
        if (!$endpoint) {
            $endpoint = 'https://s3.' . $this->region . '.amazonaws.com';
        } elseif (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        $origin = self::parseOrigin($endpoint);
        $this->scheme = $origin['scheme'];
        $this->host = $origin['host'];
        $this->port = $origin['port'];
        $this->basePath = $origin['basePath'];

        if (!empty($config['public_endpoint'])) {
            $this->publicOrigin = self::parseOrigin((string) $config['public_endpoint']);
        }
    }

    /**
     * @return array{scheme: string, host: string, port: ?int, basePath: string}
     */
    private static function parseOrigin(string $endpoint): array
    {
        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }
        $parts = parse_url($endpoint);
        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid S3 endpoint: ' . $endpoint);
        }
        return [
            'scheme' => strtolower($parts['scheme'] ?? 'https'),
            'host' => $parts['host'],
            'port' => $parts['port'] ?? null,
            'basePath' => rtrim($parts['path'] ?? '', '/'),
        ];
    }

    private function origin(): array
    {
        return ['scheme' => $this->scheme, 'host' => $this->host, 'port' => $this->port, 'basePath' => $this->basePath];
    }

    // ---------------------------------------------------------------- operations

    /**
     * Uploads a local file.
     *
     * @throws S3Exception
     */
    public function putObject(string $key, string $sourceFile, ?string $contentType = null): void
    {
        $size = filesize($sourceFile);
        $payloadHash = hash_file('sha256', $sourceFile);
        if ($size === false || $payloadHash === false) {
            throw new S3Exception('Cannot read source file: ' . $sourceFile);
        }

        $headers = ['Content-Length' => (string) $size];
        if ($contentType) {
            $headers['Content-Type'] = $contentType;
        }

        $handle = fopen($sourceFile, 'rb');
        try {
            $this->send('PUT', $key, [], $headers, $payloadHash, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $handle,
                CURLOPT_INFILESIZE => $size,
            ]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Opens an object for streaming. Returns ['Body' => resource] (a read-only PHP stream that
     * downloads on demand), so large objects are never held in memory.
     *
     * @param array $range Optional ['start' => int, 'end' => int|null]
     * @throws S3Exception
     */
    public function getObject(string $key, ?array $range = null): array
    {
        $headers = [];
        if ($range !== null) {
            $headers['Range'] = 'bytes=' . (int) $range['start'] . '-' . (isset($range['end']) ? (int) $range['end'] : '');
        }

        $signed = $this->signHeaders('GET', $key, [], $headers, self::EMPTY_PAYLOAD_HASH);
        S3ObjectStream::register();
        $context = stream_context_create([
            S3ObjectStream::PROTOCOL => ['url' => $this->url($key, []), 'headers' => $signed],
        ]);

        $stream = fopen(S3ObjectStream::PROTOCOL . '://object', 'rb', false, $context);
        if (!$stream) {
            throw new S3Exception('Could not open S3 object stream for ' . $key);
        }
        stream_set_chunk_size($stream, 1048576);
        return ['Body' => $stream];
    }

    /**
     * Gets object metadata, or null if the object does not exist.
     *
     * @return array|null ['ContentLength' => int, 'ContentType' => string|null, 'ETag' => string|null]
     * @throws S3Exception for any failure other than "not found"
     */
    public function headObject(string $key): ?array
    {
        try {
            $response = $this->send('HEAD', $key);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
        return [
            'ContentLength' => (int) ($response['headers']['content-length'] ?? 0),
            'ContentType' => $response['headers']['content-type'] ?? null,
            'ETag' => $response['headers']['etag'] ?? null,
        ];
    }

    public function doesObjectExist(string $key): bool
    {
        return $this->headObject($key) !== null;
    }

    /**
     * @throws S3Exception
     */
    public function deleteObject(string $key): void
    {
        $this->send('DELETE', $key);
    }

    /**
     * Lists objects under a prefix (one page).
     *
     * @param array $params Optional 'Prefix', 'ContinuationToken', 'MaxKeys'
     * @return array ['Contents' => [['Key' => string, 'Size' => int, 'LastModified' => string], ...], 'NextContinuationToken' => string|null]
     * @throws S3Exception
     */
    public function listObjectsV2(array $params = []): array
    {
        $query = ['list-type' => '2'];
        if (!empty($params['Prefix'])) {
            $query['prefix'] = $params['Prefix'];
        }
        if (!empty($params['ContinuationToken'])) {
            $query['continuation-token'] = $params['ContinuationToken'];
        }
        if (!empty($params['MaxKeys'])) {
            $query['max-keys'] = (string) (int) $params['MaxKeys'];
        }

        $response = $this->send('GET', null, $query);
        $xml = $this->parseXml($response['body']);
        if ($xml === null) {
            throw new S3Exception('Invalid response to list objects request', $response['status']);
        }

        $contents = [];
        foreach ($xml->Contents ?? [] as $object) {
            $contents[] = ['Key' => (string) $object->Key, 'Size' => (int) $object->Size, 'LastModified' => (string) ($object->LastModified ?? '')];
        }
        $truncated = strtolower((string) ($xml->IsTruncated ?? '')) === 'true';
        $next = (string) ($xml->NextContinuationToken ?? '');

        return [
            'Contents' => $contents,
            'NextContinuationToken' => ($truncated && $next !== '') ? $next : null,
        ];
    }

    /**
     * @throws S3Exception when the bucket is missing or the credentials are rejected
     */
    public function headBucket(): void
    {
        $this->send('HEAD', null);
    }

    /**
     * Creates a presigned URL (query-string authentication).
     *
     * The URL is signed for, and points at, the public endpoint when one is configured: browsers
     * reach the bucket at a different address than this server does (for example through an HTTPS
     * proxy in front of the dev storage). The proxy must pass the Host header through unchanged,
     * because the signature covers it.
     *
     * @param string $method GET or PUT
     * @param array $extraQuery Additional signed query parameters, e.g. ['response-content-disposition' => 'attachment']
     * @param int|null $now Unix time to sign for (tests only)
     */
    public function presignUrl(string $method, string $key, int $expiresIn = 3600, array $extraQuery = [], ?int $now = null): string
    {
        $origin = $this->publicOrigin ?? $this->origin();
        $now = $now ?? time();
        $date = gmdate('Ymd\THis\Z', $now);
        $day = gmdate('Ymd', $now);
        $scope = $day . '/' . $this->region . '/s3/aws4_request';

        $query = $extraQuery + [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) max(1, min($expiresIn, 604800)),
            'X-Amz-SignedHeaders' => 'host',
        ];

        $canonicalRequest = implode("\n", [
            $method,
            $this->canonicalPath($key, $origin),
            $this->canonicalQuery($query),
            'host:' . $this->hostHeader($origin) . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $query['X-Amz-Signature'] = $this->signature($canonicalRequest, $date, $day);

        return $this->url($key, $query, $origin);
    }

    // ------------------------------------------------------------------ signing

    /**
     * Signs a request with an Authorization header.
     *
     * @return array Headers to send, as 'Name' => 'value'
     */
    private function signHeaders(string $method, ?string $key, array $query, array $headers, string $payloadHash, ?int $now = null): array
    {
        $now = $now ?? time();
        $date = gmdate('Ymd\THis\Z', $now);
        $day = gmdate('Ymd', $now);

        $headers['Host'] = $this->hostHeader();
        $headers['X-Amz-Content-Sha256'] = $payloadHash;
        $headers['X-Amz-Date'] = $date;

        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim(preg_replace('/\s+/', ' ', (string) $value));
        }
        ksort($canonical);

        $canonicalHeaders = '';
        foreach ($canonical as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaders = implode(';', array_keys($canonical));

        $canonicalRequest = implode("\n", [
            $method,
            $this->canonicalPath($key),
            $this->canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $day . '/' . $this->region
            . '/s3/aws4_request, SignedHeaders=' . $signedHeaders
            . ', Signature=' . $this->signature($canonicalRequest, $date, $day);

        return $headers;
    }

    private function signature(string $canonicalRequest, string $date, string $day): string
    {
        $scope = $day . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $date, $scope, hash('sha256', $canonicalRequest)]);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $this->region,
                    hash_hmac('sha256', $day, 'AWS4' . $this->secretKey, true), true), true), true);

        return hash_hmac('sha256', $stringToSign, $signingKey);
    }

    private function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string) $name)] = rawurlencode((string) $value);
        }
        ksort($pairs, SORT_STRING);
        $parts = [];
        foreach ($pairs as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('&', $parts);
    }

    // --------------------------------------------------------------- addressing

    private function hostHeader(?array $origin = null): string
    {
        $origin ??= $this->origin();
        $host = $this->pathStyle ? $origin['host'] : $this->bucket . '.' . $origin['host'];
        $default = $origin['scheme'] === 'https' ? 443 : 80;
        return ($origin['port'] !== null && $origin['port'] !== $default) ? $host . ':' . $origin['port'] : $host;
    }

    private function canonicalPath(?string $key, ?array $origin = null): string
    {
        $path = ($origin ?? $this->origin())['basePath'];
        if ($this->pathStyle) {
            $path .= '/' . rawurlencode($this->bucket);
        }
        if ($key !== null && $key !== '') {
            $path .= '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        } elseif (!$this->pathStyle) {
            $path .= '/';
        }
        return $path === '' ? '/' : $path;
    }

    private function url(?string $key, array $query, ?array $origin = null): string
    {
        $origin ??= $this->origin();
        $url = $origin['scheme'] . '://' . $this->hostHeader($origin) . $this->canonicalPath($key, $origin);
        $queryString = $this->canonicalQuery($query);
        return $queryString === '' ? $url : $url . '?' . $queryString;
    }

    // --------------------------------------------------------------- transport

    /**
     * Sends a signed request and returns the response.
     *
     * @return array ['status' => int, 'headers' => array (lower-case names), 'body' => string]
     * @throws S3Exception on a transport error or a non-2xx response
     */
    private function send(string $method, ?string $key, array $query = [], array $headers = [], string $payloadHash = self::EMPTY_PAYLOAD_HASH, array $curlOptions = []): array
    {
        $signed = $this->signHeaders($method, $key, $query, $headers, $payloadHash);
        $headerLines = [];
        foreach ($signed as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $ch = curl_init($this->url($key, $query));
        curl_setopt_array($ch, $curlOptions + [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                if (stripos($line, 'HTTP/') === 0) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($method === 'PUT' && !isset($curlOptions[CURLOPT_UPLOAD])) {
            // Empty body: curl would otherwise omit Content-Length
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new S3Exception('S3 request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw self::errorFromResponse($status, is_string($body) ? $body : '');
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => is_string($body) ? $body : ''];
    }

    /**
     * Builds an exception from an error response body.
     */
    public static function errorFromResponse(int $status, string $body): S3Exception
    {
        $code = null;
        $message = 'S3 request failed with HTTP ' . $status;
        $xml = self::parseXml($body);
        if ($xml !== null && isset($xml->Code)) {
            $code = (string) $xml->Code;
            $message .= ' (' . $code . ($xml->Message ? ': ' . $xml->Message : '') . ')';
        }
        return new S3Exception($message, $status, $code);
    }

    private static function parseXml(string $body): ?\SimpleXMLElement
    {
        if (trim($body) === '' || !function_exists('simplexml_load_string')) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $xml === false ? null : $xml;
    }
}
