<?php

/**
 * VideoFormatDetector.php
 *
 * Utility class for detecting video format and codec information using FFprobe.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

namespace Phuppi;

class VideoFormatDetector
{
    /**
     * Path to ffprobe command.
     *
     * @var string
     */
    private string $ffprobePath = 'ffprobe';

    /**
     * Browser-compatible video formats.
     *
     * @var array
     */
    private array $compatibleFormats = ['mp4', 'webm', 'ogg'];

    /**
     * Browser-compatible video codecs.
     *
     * @var array
     */
    private array $compatibleVideoCodecs = ['h264', 'avc', 'vp8', 'vp9', 'theora'];

    /**
     * Browser-compatible audio codecs.
     *
     * @var array
     */
    private array $compatibleAudioCodecs = ['aac', 'vorbis', 'opus', 'mp3'];

    /**
     * Check if a video is browser-compatible.
     *
     * A video is considered browser-compatible if:
     * - Container format is MP4, WebM, or OGG
     * - Video codec is H.264, VP8, VP9, or Theora
     * - Audio codec is AAC, Vorbis, Opus, or MP3
     *
     * @param string $filePath Path to the video file.
     * @return bool True if the video can play in a browser.
     */
    public function isBrowserCompatible(string $filePath): bool
    {
        $formatInfo = $this->getFormatInfo($filePath);

        if (empty($formatInfo)) {
            return false;
        }

        // Check container format
        $format = strtolower($formatInfo['format'] ?? '');
        if (!in_array($format, $this->compatibleFormats)) {
            return false;
        }

        // Check video codec
        $videoCodec = strtolower($formatInfo['video_codec'] ?? '');
        if (!in_array($videoCodec, $this->compatibleVideoCodecs)) {
            return false;
        }

        // Check audio codec (optional - some videos may not have audio)
        $audioCodec = strtolower($formatInfo['audio_codec'] ?? '');
        if (!empty($audioCodec) && !in_array($audioCodec, $this->compatibleAudioCodecs)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a video needs transcoding.
     *
     * Per the video preview transcoding plan, this always returns true
     * to ensure consistent quality and format across all videos.
     *
     * @param string $filePath Path to the video file.
     * @return bool Always true.
     */
    public function needsTranscoding(string $filePath): bool
    {
        // Per plan: always transcode for consistent quality
        return true;
    }

    /**
     * Get detailed format information for a video file.
     *
     * @param string $filePath Path to the video file.
     * @return array Format information including codec, duration, resolution, etc.
     */
    public function getFormatInfo(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        $jsonOutput = $this->runFFprobe($filePath);

        if (empty($jsonOutput)) {
            return [];
        }

        $data = json_decode($jsonOutput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $this->parseFormatData($data);
    }

    /**
     * Get the video codec name.
     *
     * @param string $filePath Path to the video file.
     * @return string|null The video codec name or null if not found.
     */
    public function getVideoCodec(string $filePath): ?string
    {
        $formatInfo = $this->getFormatInfo($filePath);
        return $formatInfo['video_codec'] ?? null;
    }

    /**
     * Get the audio codec name.
     *
     * @param string $filePath Path to the video file.
     * @return string|null The audio codec name or null if not found.
     */
    public function getAudioCodec(string $filePath): ?string
    {
        $formatInfo = $this->getFormatInfo($filePath);
        return $formatInfo['audio_codec'] ?? null;
    }

    /**
     * Get the video duration in seconds.
     *
     * @param string $filePath Path to the video file.
     * @return float|null Duration in seconds or null if not found.
     */
    public function getDuration(string $filePath): ?float
    {
        $formatInfo = $this->getFormatInfo($filePath);
        return $formatInfo['duration'] ?? null;
    }

    /**
     * Get the video resolution (width x height).
     *
     * @param string $filePath Path to the video file.
     * @return array|null Associative array with 'width' and 'height' keys, or null if not found.
     */
    public function getResolution(string $filePath): ?array
    {
        $formatInfo = $this->getFormatInfo($filePath);
        return $formatInfo['resolution'] ?? null;
    }

    /**
     * Get the video bitrate in kbps.
     *
     * @param string $filePath Path to the video file.
     * @return int|null Bitrate in kbps or null if not found.
     */
    public function getBitrate(string $filePath): ?int
    {
        $formatInfo = $this->getFormatInfo($filePath);
        return $formatInfo['bitrate'] ?? null;
    }

    /**
     * Run ffprobe and return JSON output.
     *
     * @param string $filePath Path to the video file.
     * @return string|null JSON output or null on failure.
     */
    private function runFFprobe(string $filePath): ?string
    {
        $command = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s',
            escapeshellcmd($this->ffprobePath),
            escapeshellarg($filePath)
        );

        $output = shell_exec($command);

        return $output !== false ? $output : null;
    }

    /**
     * Parse ffprobe JSON output into a structured array.
     *
     * @param array $data Raw ffprobe data.
     * @return array Parsed format information.
     */
    private function parseFormatData(array $data): array
    {
        $result = [
            'format' => null,
            'duration' => null,
            'bitrate' => null,
            'size' => null,
            'video_codec' => null,
            'video_profile' => null,
            'video_resolution' => null,
            'resolution' => null,
            'frame_rate' => null,
            'audio_codec' => null,
            'audio_channels' => null,
            'audio_sample_rate' => null,
        ];

        // Parse format info
        if (isset($data['format'])) {
            $format = $data['format'];
            $result['format'] = $format['format_name'] ?? null;
            $result['duration'] = isset($format['duration']) ? (float) $format['duration'] : null;
            $result['bitrate'] = isset($format['bit_rate']) ? (int) ((float) $format['bit_rate'] / 1000) : null;
            $result['size'] = isset($format['size']) ? (int) $format['size'] : null;
        }

        // Parse streams
        if (isset($data['streams']) && is_array($data['streams'])) {
            foreach ($data['streams'] as $stream) {
                $codecType = $stream['codec_type'] ?? '';

                if ($codecType === 'video') {
                    $result['video_codec'] = $this->normalizeCodecName($stream['codec_name'] ?? null);
                    $result['video_profile'] = $stream['profile'] ?? null;
                    $result['video_resolution'] = sprintf(
                        '%dx%d',
                        $stream['width'] ?? 0,
                        $stream['height'] ?? 0
                    );
                    $result['resolution'] = [
                        'width' => $stream['width'] ?? 0,
                        'height' => $stream['height'] ?? 0,
                    ];
                    $result['frame_rate'] = isset($stream['r_frame_rate']) 
                        ? (float) explode('/', $stream['r_frame_rate'])[0] / max(1, (int) (explode('/', $stream['r_frame_rate'])[1] ?? 1))
                        : null;
                } elseif ($codecType === 'audio') {
                    $result['audio_codec'] = $this->normalizeCodecName($stream['codec_name'] ?? null);
                    $result['audio_channels'] = $stream['channels'] ?? null;
                    $result['audio_sample_rate'] = isset($stream['sample_rate']) ? (int) $stream['sample_rate'] : null;
                }
            }
        }

        return $result;
    }

    /**
     * Normalize codec name to a standard format.
     *
     * @param string|null $codecName Raw codec name.
     * @return string|null Normalized codec name.
     */
    private function normalizeCodecName(?string $codecName): ?string
    {
        if ($codecName === null) {
            return null;
        }

        // Normalize common codec names
        $normalized = strtolower($codecName);

        // Map common codec names
        $codecMap = [
            'h264' => 'h264',
            'avc' => 'h264',
            'h265' => 'h265',
            'hevc' => 'h265',
            'vp8' => 'vp8',
            'vp9' => 'vp9',
            'theora' => 'theora',
            'aac' => 'aac',
            'vorbis' => 'vorbis',
            'opus' => 'opus',
            'mp3' => 'mp3',
            'libx264' => 'h264',
            'libx265' => 'h265',
            'libvpx' => 'vp8',
            'libvpx-vp9' => 'vp9',
        ];

        return $codecMap[$normalized] ?? $normalized;
    }

    /**
     * Set the path to ffprobe command.
     *
     * @param string $path Path to ffprobe executable.
     * @return self
     */
    public function setFFprobePath(string $path): self
    {
        $this->ffprobePath = $path;
        return $this;
    }

    /**
     * Get the path to ffprobe command.
     *
     * @return string
     */
    public function getFFprobePath(): string
    {
        return $this->ffprobePath;
    }
}
