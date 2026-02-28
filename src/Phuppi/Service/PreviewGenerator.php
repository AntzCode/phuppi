<?php
/**
 * PreviewGenerator.php
 *
 * Service class for generating preview images for uploaded files.
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
use Exception;

class PreviewGenerator
{
    private array $config;

    public function __construct()
    {
        $this->config = $this->getConfig();
    }

    /**
     * Generate preview for a file
     *
     * @param int $fileId
     * @param bool $skipPermissionCheck Whether to skip permission check (for queue workers)
     * @return bool Success
     */
    public function generate(int $fileId, bool $skipPermissionCheck = false): bool
    {
        $file = UploadedFile::findById($fileId);
        if (!$file) {
            Flight::logger()->error("PreviewGenerator: File not found ID $fileId");
            return false;
        }

        // Permission check - skip for queue workers that have no authenticated session
        if (!$skipPermissionCheck && !Helper::can('files.view', $file)) {
            Flight::logger()->warning("PreviewGenerator: Permission denied for file ID $fileId");
            return false;
        }

        $storage = Flight::storage();
        $originalKey = $file->getUsername() . '/' . $file->filename;
        if (!$storage->exists($originalKey)) {
            Flight::logger()->error("PreviewGenerator: Original file not found $originalKey");
            return false;
        }

        $previewKey = $this->getPreviewKey($file);
        $mime = $file->mimetype;

        // Update status to processing
        $file->preview_status = 'processing';
        $file->save();

        $tempOriginal = $this->streamToTemp($storage->getStream($originalKey));
        if (!$tempOriginal) {
            $file->preview_status = 'failed';
            $file->save();
            return false;
        }

        try {
            $tempPreview = null;
            if (str_starts_with($mime, 'image/')) {
                $tempPreview = $this->generateImagePreview($tempOriginal);
            } elseif (str_starts_with($mime, 'video/')) {
                $tempPreview = $this->generateVideoPreview($tempOriginal);
            } elseif ($mime === 'application/pdf') {
                $tempPreview = $this->generatePdfPreview($tempOriginal);
            } else {
            
                throw new Exception('Unsupported mime type for preview: ' . $mime);
            }

            if (!$tempPreview) {
                throw new Exception('Preview generation failed');
            }

            // Compress if needed
            $this->compressPreview($tempPreview);

            // Save to storage
            if (!$storage->put($previewKey, $tempPreview)) {
                throw new Exception('Failed to save preview to storage');
            }

            unlink($tempOriginal);
            unlink($tempPreview);

            // Update file record
            $file->preview_filename = basename($previewKey);
            $file->preview_status = 'completed';
            $file->preview_generated_at = date('Y-m-d H:i:s');
            $file->save();

            Flight::logger()->info("Preview generated successfully for file ID $fileId");
            return true;

        } catch (Exception $e) {
            Flight::logger()->error("Preview generation failed for file ID $fileId: " . $e->getMessage());
            $file->preview_status = 'failed';
            $file->save();
            if (isset($tempOriginal)) unlink($tempOriginal);
            if (isset($tempPreview)) unlink($tempPreview);
            return false;
        }
    }

    private function getConfig(): array
    {
        $db = Flight::db();
        $defaults = [
            'width' => 300,
            'height' => 300,
            'format' => 'jpeg',
            'quality' => 80,
            'max_size_kb' => 50
        ];

        $stmt = $db->prepare('SELECT name, value FROM settings WHERE name LIKE "preview_%"');
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = substr($row['name'], 8); // remove 'preview_'
            $settings[$key] = $row['value'];
        }

        return array_merge($defaults, $settings);
    }

    private function getPreviewKey(UploadedFile $file): string
    {
        $format = $this->config['format'];
        // Get the original filename without extension and append the preview format
        $originalFilename = pathinfo($file->filename, PATHINFO_FILENAME);
        $previewFilename = $originalFilename . '.' . $format;
        return $file->getUsername() . '/previews/' . $previewFilename;
    }

    private function streamToTemp($stream): ?string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'preview_');
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

    private function generateImagePreview(string $tempPath): ?string
    {
        $ext = pathinfo($tempPath, PATHINFO_EXTENSION);
        $imageInfo = getimagesize($tempPath);
        if (!$imageInfo) return null;

        // Check for extremely large images that would cause memory issues
        $pixels = ($imageInfo[0] ?? 0) * ($imageInfo[1] ?? 0);
        if ($pixels > 50000000) { // 50 megapixels - too large
            Flight::logger()->warning("Image too large for preview generation: {$pixels} pixels");
            return null;
        }

        // Increase memory limit for large images
        if ($pixels > 10000000) { // 10 megapixels
            ini_set('memory_limit', '512M');
        }

        $width = $this->config['width'];
        $height = $this->config['height'];
        $format = $this->config['format'];

        $srcImage = $this->createImageFromFile($tempPath);
        if (!$srcImage) return null;

        /** @var \GdImage $srcImage */
        $thumb = imagecreatetruecolor($width, $height);
        
        // Fill with white background (for transparent images)
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        
        imagecopyresampled($thumb, $srcImage, 0, 0, 0, 0, $width, $height, $imageInfo[0], $imageInfo[1]);

        $tempPreview = tempnam(sys_get_temp_dir(), 'preview_thumb_');
        $this->saveImage($thumb, $tempPreview, $format);

        imagedestroy($srcImage);
        imagedestroy($thumb);
        
        // Reset memory limit only if we increased it and current usage allows
        $currentMemory = memory_get_usage(true);
        if ($currentMemory < 134217728) { // Only reset if under 128M
            ini_set('memory_limit', '128M');
        }

        return $tempPreview;
    }

    private function createImageFromFile(string $tempPath): \GdImage | false
    {
        // Use getimagesize to detect the actual image type from file content
        // (tempnam creates files without extensions)
        $imageInfo = @getimagesize($tempPath);
        if (!$imageInfo) {
            return false;
        }
        
        $mime = $imageInfo['mime'] ?? '';
        switch ($mime) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($tempPath) ?: false;
            case 'image/png':
                return @imagecreatefrompng($tempPath) ?: false;
            case 'image/gif':
                return @imagecreatefromgif($tempPath) ?: false;
            case 'image/webp':
                return @imagecreatefromwebp($tempPath) ?: false;
            default:
                return false;
        }
    }

    private function saveImage($image, string $tempPath, string $format): bool
    {
        $quality = $this->config['quality'];
        switch (strtolower($format)) {
            case 'jpeg':
                return imagejpeg($image, $tempPath, $quality);
            case 'webp':
                return imagewebp($image, $tempPath, $quality);
            default:
                return false;
        }
    }

    private function generateVideoPreview(string $tempPath): ?string
    {
        $tempPreview = tempnam(sys_get_temp_dir(), 'preview_video_') . '.jpg';
        $width = $this->config['width'];
        $height = $this->config['height'];
        $format = $this->config['format'];

        // extract first frame
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -i %s -vframes 1 -vf "scale=%d:%d" -y %s 2>&1',
            escapeshellarg($tempPath),
            $width,
            $height,
            escapeshellarg($tempPreview)
        );
        
        Flight::logger()->debug($cmd);

        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            Flight::logger()->error("Failed to generate video preview (return code $returnCode): " . implode("\n", $output));
            unlink($tempPreview);
            return null;
        }

        if (!file_exists($tempPreview)) {
            Flight::logger()->error("Failed to generate video preview: {$tempPreview} not found");
            return null;
        }

        // Convert to target format if needed
        if ($format !== 'jpeg') {
            Flight::logger()->info("Converting video preview to $format");
            $converted = tempnam(sys_get_temp_dir(), 'preview_converted_') . '.' . $format;
            $this->convertToFormat($tempPreview, $converted, $format);
            unlink($tempPreview);
            $tempPreview = $converted;
        }
        Flight::logger()->info("Generated video preview: $tempPreview");

        return $tempPreview;
    }

    private function generatePdfPreview(string $tempPath): ?string
    {
        if (!extension_loaded('imagick')) {
            Flight::logger()->warning('Imagick not available for PDF preview');
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($tempPath . '[0]');
            $imagick->setImageFormat($this->config['format']);
            $imagick->setImageCompressionQuality($this->config['quality']);
            $imagick->scaleImage($this->config['width'], $this->config['height']);

            $tempPreview = tempnam(sys_get_temp_dir(), 'preview_pdf_');
            $imagick->writeImage($tempPreview);
            $imagick->clear();
            $imagick->destroy();

            return $tempPreview;
        } catch (\Exception $e) {
            Flight::logger()->error('PDF preview generation failed: ' . $e->getMessage());
            return null;
        }
    }

    private function compressPreview(string $tempPreview): void
    {
        $maxSize = (int)$this->config['max_size_kb'] * 1024;
        $currentSize = filesize($tempPreview);

        if ($currentSize <= $maxSize) return;

        // Simple compression by reducing quality iteratively
        $quality = $this->config['quality'];
        $format = pathinfo($tempPreview, PATHINFO_EXTENSION);

        while ($quality > 10 && filesize($tempPreview) > $maxSize) {
            $quality -= 10;
            $tempCompressed = tempnam(sys_get_temp_dir(), 'preview_compressed_');
            $image = $this->loadImage($tempPreview);
            if ($image) {
                $this->saveImage($image, $tempCompressed, $format);
                imagedestroy($image);
                unlink($tempPreview);
                rename($tempCompressed, $tempPreview);
            }
        }
    }

    private function loadImage(string $tempPath): \GdImage|false
    {
        // Use getimagesize to detect the actual image type from file content
        // (tempnam creates files without extensions)
        $imageInfo = @getimagesize($tempPath);
        if (!$imageInfo) {
            return false;
        }
        
        $mime = $imageInfo['mime'] ?? '';
        switch ($mime) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($tempPath) ?: false;
            case 'image/png':
                return @imagecreatefrompng($tempPath) ?: false;
            case 'image/webp':
                return @imagecreatefromwebp($tempPath) ?: false;
            default:
                return false;
        }
    }

    private function convertToFormat(string $input, string $output, string $format): bool
    {
        $cmd = sprintf(
            'ffmpeg -i %s -y %s 2>/dev/null',
            escapeshellarg($input),
            escapeshellarg($output)
        );
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }
}
