<?php

/**
 * S3ObjectStream.php
 *
 * Read-only PHP stream wrapper that downloads an S3 object on demand with curl_multi, so
 * multi-gigabyte files can be streamed to the browser without being buffered in memory.
 * Created through S3Service::getObject(); the request is already signed.
 *
 * @package Phuppi\Storage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.7.0
 */

namespace Phuppi\Storage;

class S3ObjectStream
{
    public const PROTOCOL = 's3object';

    /** @var resource|null Set by PHP: the stream context passed to fopen() */
    public $context;

    private $multi;
    private $curl;
    private string $buffer = '';
    private bool $headersDone = false;
    private bool $finished = false;
    private int $status = 0;
    private int $curlResult = CURLE_OK;

    public static function register(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $config = stream_context_get_options($this->context)[self::PROTOCOL] ?? null;
        if (!$config) {
            return false;
        }

        $headerLines = [];
        foreach ($config['headers'] as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $this->curl = curl_init($config['url']);
        curl_setopt_array($this->curl, [
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) {
                if (stripos($line, 'HTTP/') === 0) {
                    $this->status = (int) (explode(' ', $line)[1] ?? 0);
                    $this->headersDone = false;
                } elseif (trim($line) === '' && $this->status !== 100) {
                    $this->headersDone = true;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) {
                $this->buffer .= $data;
                return strlen($data);
            },
        ]);
        $this->multi = curl_multi_init();
        curl_multi_add_handle($this->multi, $this->curl);

        // Wait for the response headers so a failure surfaces at open time
        while (!$this->headersDone && !$this->finished) {
            $this->step();
        }

        if ($this->finished && !$this->headersDone) {
            $this->closeCurl();
            throw new S3Exception('S3 request failed: ' . curl_strerror($this->curlResult));
        }
        if ($this->status < 200 || $this->status >= 300) {
            while (!$this->finished) {
                $this->step();
            }
            $status = $this->status;
            $body = $this->buffer;
            $this->closeCurl();
            throw S3Service::errorFromResponse($status, $body);
        }

        return true;
    }

    public function stream_read(int $count)
    {
        while (strlen($this->buffer) < $count && !$this->finished) {
            $this->step();
        }

        if ($this->buffer === '') {
            return $this->curlResult === CURLE_OK ? '' : false;
        }

        $chunk = substr($this->buffer, 0, $count);
        $this->buffer = (string) substr($this->buffer, strlen($chunk));
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->finished && $this->buffer === '';
    }

    public function stream_stat()
    {
        return [];
    }

    public function stream_close(): void
    {
        $this->closeCurl();
    }

    /**
     * Drives the transfer once, waiting briefly for network activity.
     */
    private function step(): void
    {
        $running = 0;
        curl_multi_exec($this->multi, $running);

        while ($info = curl_multi_info_read($this->multi)) {
            if ($info['msg'] === CURLMSG_DONE) {
                $this->finished = true;
                $this->curlResult = $info['result'];
            }
        }

        if (!$this->finished && $running > 0 && curl_multi_select($this->multi, 1.0) === -1) {
            usleep(1000);
        }
    }

    private function closeCurl(): void
    {
        if ($this->multi) {
            if ($this->curl) {
                curl_multi_remove_handle($this->multi, $this->curl);
                curl_close($this->curl);
            }
            curl_multi_close($this->multi);
        }
        $this->multi = $this->curl = null;
        $this->finished = true;
    }
}
