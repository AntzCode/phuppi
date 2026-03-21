<?php
/**
 * VideoPreviewGenerator.php
 *
 * Service class for transcoding videos to browser-compatible MP4 format.
 *
 * @package Phuppi\Service
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.1.0
 */

namespace Phuppi\Service;

use Flight;
use PDO;
use Phuppi\UploadedFile;
use Phuppi\Helper;
use Phuppi\Storage\StorageFactory;
use Phuppi\Service\TransferStats;
use Exception;

class VideoPreviewGenerator
{
    private array $config;

    /**
     * Quality presets (CRF values for x264)
     * Lower CRF = higher quality, larger file size
     */
    private const QUALITY_PRESETS = [
        'crushed' => 35,
        'low' => 28,
        'medium' => 23,
        'high' => 18
    ];

    /**
     * Resolution presets (height in pixels)
     */
    private const RESOLUTION_PRESETS = [
        '240p' => 240,
        '360p' => 360,
        '480p' => 480,
        '720p' => 720,
        '1080p' => 1080
    ];

    public function __construct()
    {
        $this->config = $this->getConfig();
    }

    /**
     * Records transfer statistics for video preview generation.
     *
     * @param UploadedFile $file The file being processed.
     * @param string $direction Direction of transfer (TransferStats::DIRECTION_INGRESS or EGRESS).
     * @param int $bytesTransferred Bytes transferred.
     * @param string $operationType Type of operation.
     * @return void
     */
    private function recordTransferStats(UploadedFile $file, string $direction, int $bytesTransferred, string $operationType): void
    {
        try {
            $transferStats = new TransferStats();
            $user = Flight::user();
            $voucher = Flight::voucher();

            if ($direction === TransferStats::DIRECTION_INGRESS) {
                $transferStats->recordIngress(
                    $transferStats->getCurrentConnectorName(),
                    $file->id,
                    $file->user_id,
                    $file->voucher_id,
                    $operationType,
                    $bytesTransferred
                );
            } else {
                $transferStats->recordEgress(
                    $transferStats->getCurrentConnectorName(),
                    $file->id,
                    $file->user_id,
                    $file->voucher_id,
                    $operationType,
                    $bytesTransferred
                );
            }
        } catch (\Exception $e) {
            // Log but don't fail video preview generation if stats recording fails
            Flight::logger()->warning('Failed to record video preview transfer stats: ' . $e->getMessage());
        }
    }

    /**
     * Generate video preview (transcoded MP4 and poster) for a file
     *
     * @param int $fileId The file ID to generate preview for
     * @param bool $skipPermissionCheck Whether to skip permission check (for queue workers)
     * @return bool Success
     */
    public function generate(int $fileId, bool $skipPermissionCheck = false): bool
    {
        $file = UploadedFile::findById($fileId);
        if (!$file) {
            Flight::logger()->error("VideoPreviewGenerator: File not found ID $fileId");
            return false;
        }

        // Check if this is a video file
        if (!str_starts_with($file->mimetype, 'video/')) {
            Flight::logger()->warning("VideoPreviewGenerator: File ID $fileId is not a video (mimetype: {$file->mimetype})");
            return false;
        }

        // Permission check - skip for queue workers that have no authenticated session
        if (!$skipPermissionCheck && !Helper::can('files.view', $file)) {
            Flight::logger()->warning("VideoPreviewGenerator: Permission denied for file ID $fileId");
            return false;
        }

        // Use the storage connector that was used when the file was uploaded
        // Fall back to active connector if storage_connector is not set (legacy files)
        $connectorName = $file->storage_connector ?? StorageFactory::getActiveConnector();
        try {
            $storage = StorageFactory::createConnectorByName($connectorName);
        } catch (\InvalidArgumentException $e) {
            // Fall back to active connector if the stored connector is no longer available
            Flight::logger()->warning("VideoPreviewGenerator: Stored connector '$connectorName' not available, using active connector");
            $storage = Flight::storage();
        }
        $originalKey = $file->getUsername() . '/' . $file->filename;
        if (!$storage->exists($originalKey)) {
            Flight::logger()->error("VideoPreviewGenerator: Original file not found $originalKey");
            return false;
        }

        // Update status to processing
        $file->video_preview_status = 'processing';
        $file->save();

        $tempOriginal = $this->streamToTemp($storage->getStream($originalKey));
        if (!$tempOriginal) {
            $file->video_preview_status = 'failed';
            $file->save();
            return false;
        }

        // Record ingress transfer (reading original video from storage)
        $this->recordTransferStats($file, TransferStats::DIRECTION_INGRESS, $file->filesize, TransferStats::OPERATION_PREVIEW_GENERATE_INGRESS);

        try {
            // Transcode to MP4
            $tempMp4 = tempnam(sys_get_temp_dir(), 'video_preview_mp4_') . '.mp4';
            if (!$this->transcodeToMp4($tempOriginal, $tempMp4)) {
                throw new Exception('Video transcoding to MP4 failed');
            }

            // Generate poster frame
            $tempPoster = null;
            if ($this->config['generate_poster'] ?? true) {
                $tempPoster = tempnam(sys_get_temp_dir(), 'video_poster_') . '.jpg';
                if (!$this->generatePoster($tempOriginal, $tempPoster)) {
                    Flight::logger()->warning("Poster generation failed for file ID $fileId, continuing without poster");
                    $tempPoster = null;
                }
            }

            // Save MP4 to storage
            $mp4Key = $this->getVideoPreviewKey($file, 'mp4');
            if (!$storage->put($mp4Key, $tempMp4)) {
                throw new Exception('Failed to save transcoded video to storage');
            }

            // Record egress transfer (writing video preview MP4 to storage)
            $mp4Size = filesize($tempMp4);
            $this->recordTransferStats($file, TransferStats::DIRECTION_EGRESS, $mp4Size, TransferStats::OPERATION_PREVIEW_GENERATE_EGRESS);

            // Save poster to storage if generated
            $posterKey = null;
            if ($tempPoster) {
                $posterKey = $this->getVideoPreviewKey($file, 'jpg');
                if (!$storage->put($posterKey, $tempPoster)) {
                    Flight::logger()->warning("Failed to save poster to storage for file ID $fileId");
                    unlink($tempPoster);
                    $tempPoster = null;
                } else {
                    // Record egress transfer (writing video poster to storage)
                    $posterSize = filesize($tempPoster);
                    $this->recordTransferStats($file, TransferStats::DIRECTION_EGRESS, $posterSize, TransferStats::OPERATION_PREVIEW_GENERATE_EGRESS);
                }
            }

            // Cleanup temp files
            unlink($tempOriginal);
            unlink($tempMp4);
            if ($tempPoster) unlink($tempPoster);

            // Update file record
            $file->video_preview_filename = basename($mp4Key);
            $file->video_preview_status = 'completed';
            $file->video_preview_generated_at = date('Y-m-d H:i:s');
            
            if ($tempPoster && $posterKey) {
                $file->video_preview_poster_filename = basename($posterKey);
            }
            
            $file->save();

            Flight::logger()->info("Video preview generated successfully for file ID $fileId");
            return true;

        } catch (Exception $e) {
            Flight::logger()->error("Video preview generation failed for file ID $fileId: " . $e->getMessage());
            $file->video_preview_status = 'failed';
            $file->save();
            if (isset($tempOriginal)) unlink($tempOriginal);
            if (isset($tempMp4)) unlink($tempMp4);
            if (isset($tempPoster)) unlink($tempPoster);
            return false;
        }
    }

    /**
     * Transcode a video to browser-compatible MP4 format
     *
     * @param string $inputPath Path to the input video file
     * @param string $outputPath Path where the transcoded MP4 will be saved
     * @return bool Success
     */
    public function transcodeToMp4(string $inputPath, string $outputPath): bool
    {
        $resolution = $this->config['resolution'] ?? '720p';
        $quality = $this->config['quality'] ?? 'medium';
        
        // Get height from resolution preset
        $height = self::RESOLUTION_PRESETS[$resolution] ?? 720;
        
        // Get CRF from quality preset
        $crf = self::QUALITY_PRESETS[$quality] ?? 23;

        // Build FFmpeg command
        // -hide_banner -loglevel error: Reduce output noise
        // -i input: Input file
        // -c:v libx264: Use H.264 video codec
        // -preset medium: Encoding speed/compression ratio balance
        // -crf {crf}: Quality setting
        // -c:a aac -b:a 128k: AAC audio codec with 128k bitrate
        // -vf scale=-2:{height}: Scale video to specified height (width auto, -2 for alignment)
        // -movflags +faststart: Enable progressive download
        // -y: Overwrite output file
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -i %s -c:v libx264 -preset medium -crf %d -c:a aac -b:a 128k -vf "scale=-2:%d" -movflags +faststart -y %s 2>&1',
            escapeshellarg($inputPath),
            $crf,
            $height,
            escapeshellarg($outputPath)
        );

        Flight::logger()->debug("Transcoding video: $cmd");

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            Flight::logger()->error("Video transcoding failed (return code $returnCode): " . implode("\n", $output));
            return false;
        }

        if (!file_exists($outputPath)) {
            Flight::logger()->error("Transcoded video not found at $outputPath");
            return false;
        }

        Flight::logger()->info("Video transcoded successfully to $outputPath");
        return true;
    }

    /**
     * Generate a poster frame from a video
     *
     * @param string $inputPath Path to the input video file
     * @param string $outputPath Path where the poster image will be saved
     * @return bool Success
     */
    public function generatePoster(string $inputPath, string $outputPath): bool
    {
        // Extract frame at 1 second (or first frame if video is shorter)
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -i %s -ss 00:00:01 -vframes 1 -vf "scale=1280:-2" -y %s 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        Flight::logger()->debug("Generating poster: $cmd");

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            Flight::logger()->error("Poster generation failed (return code $returnCode): " . implode("\n", $output));
            return false;
        }

        if (!file_exists($outputPath)) {
            Flight::logger()->error("Poster image not found at $outputPath");
            return false;
        }

        Flight::logger()->info("Poster generated successfully at $outputPath");
        return true;
    }

    /**
     * Get configuration from settings
     *
     * @return array Configuration array
     */
    public function getConfig(): array
    {
        $db = Flight::db();
        $defaults = [
            'resolution' => '720p',
            'quality' => 'medium',
            'generate_poster' => true
        ];

        $stmt = $db->prepare('SELECT name, value FROM settings WHERE name LIKE "video_preview_%"');
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = substr($row['name'], 14); // remove 'video_preview_'
            $settings[$key] = $row['value'];
        }

        return array_merge($defaults, $settings);
    }

    /**
     * Get the storage key for a video preview file
     *
     * @param UploadedFile $file The file
     * @param string $extension File extension (mp4, jpg)
     * @return string Storage key
     */
    private function getVideoPreviewKey(UploadedFile $file, string $extension): string
    {
        $originalFilename = pathinfo($file->filename, PATHINFO_FILENAME);
        $previewFilename = $originalFilename . '.' . $extension;
        return $file->getUsername() . '/video-previews/' . $previewFilename;
    }

    /**
     * Stream a resource to a temporary file
     *
     * @param mixed $stream The stream to read from
     * @return string|null Path to temporary file or null on failure
     */
    private function streamToTemp($stream): ?string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'video_preview_');
        if (!$tempPath) return null;

        $handle = fopen($tempPath, 'wb');
        if (!$handle) {
            unlink($tempPath);
            return null;
        }

        if (is_resource($stream)) {
            stream_copy_to_stream($stream, $handle);
        } elseif (method_exists($stream, 'read')) {
            while (!$stream->eof()) {
                fwrite($handle, $stream->read(8192));
            }
            $stream->close();
        } else {
            fclose($handle);
            unlink($tempPath);
            return null;
        }

        fclose($handle);
        return $tempPath;
    }

    /**
     * Get available resolution presets
     *
     * @return array Resolution presets
     */
    public static function getResolutionPresets(): array
    {
        return self::RESOLUTION_PRESETS;
    }

    /**
     * Get available quality presets
     *
     * @return array Quality presets
     */
    public static function getQualityPresets(): array
    {
        return self::QUALITY_PRESETS;
    }
}