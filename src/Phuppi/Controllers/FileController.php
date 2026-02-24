<?php

/**
 * FileController.php
 *
 * FileController class for managing file uploads, downloads, sharing, and storage operations in the Phuppi application.
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\Helper;
use Phuppi\UploadedFile;
use Phuppi\UploadedFileToken;
use Phuppi\BatchFileToken;
use Phuppi\ShortLink;
use Phuppi\Permissions\FilePermission;
use Phuppi\Storage\LocalStorage;
use Phuppi\Storage\S3Storage;
use Aws\S3\Exception\S3Exception;

class FileController
{

    /**
     * Displays the file index page.
     *
     * @return void
     */
    public function index(): void
    {
        $user = Flight::user();
        $voucher = Flight::voucher();

        if ($voucher->id) {
            $files = UploadedFile::findByVoucher($voucher->id);
        } elseif ($user->id) {
            $files = UploadedFile::findByUser($user->id);
        } else {
            $files = [];
        }
        Flight::render('home.latte', ['files' => $files]);
    }

    /**
     * Lists files with filtering and pagination.
     *
     * @return void
     */
    public function listFiles(): void
    {
        // Get query parameters
        $keyword = Flight::request()->query['keyword'] ?? '';
        $sort = Flight::request()->query['sort'] ?? 'date_newest';
        $page = (int)(Flight::request()->query['page'] ?? 1);
        $limit = (int)(Flight::request()->query['limit'] ?? 10);
        $offset = ($page - 1) * $limit;

        $user = Flight::user();
        $voucher = Flight::voucher();

        $result = UploadedFile::findFiltered(
            $user ? $user->id : null,
            $voucher ? $voucher->id : null,
            $keyword,
            $sort,
            $limit,
            $offset
        );

        $total = $result['total'];
        $totalPages = ceil($total / $limit);

        $fileData = array_map(function ($file) {
            return [
                'id' => $file->id,
                'display_filename' => $file->display_filename,
                'extension' => $file->extension,
                'mimetype' => $file->mimetype,
                'filesize' => $file->filesize,
                'uploaded_at' => $file->uploaded_at,
                'notes' => $file->notes,
                'voucher_id' => $file->voucher_id,
                'voucher_code' => $file->voucher_id ? $file->getVoucherCode() : null,
            ];
        }, $result['files']);

        Flight::json([
            'files' => $fileData,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'limit' => $limit
        ]);
    }

    /**
     * Gets a file by ID.
     *
     * @param int $id The file ID.
     * @return void
     */
    public function getFile($id): void
    {
        $file = UploadedFile::findById($id);

        if (!$file) {
            Flight::halt(404, 'File not found');
        }

        // Storage exists check removed - streaming will handle missing files with proper error

        // Clear any output buffers to prevent memory issues
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Zero-buffer streaming headers
        if (strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false) {
            header('X-Accel-Buffering: no');
        }
        header('Cache-Control: no-cache');
        ini_set('output_buffering', 0);
        ini_set('zlib.output_compression', 0);
        ignore_user_abort(false);

        $disposition = 'attachment';

        if (Flight::storage() instanceof S3Storage) {
            // Stream file directly from S3 using credentials (no presigned URL expiration)
            $this->streamFileFromS3($file, $disposition);
            exit;
        }

        if (Flight::storage() instanceof LocalStorage) {
            $this->streamFileFromLocal($file, $disposition);
            exit;
        }
        throw new \Exception('Unsupported storage type: ' . get_class(Flight::storage()));
    }

    /**
     * Streams a file inline for preview (images/videos).
     *
     * @param int $id The file ID.
     * @return void
     */
    public function streamInline($id): void
    {
        $file = UploadedFile::findById($id);

        if (!$file) {
            Flight::halt(404, 'File not found');
        }

        // Clear any output buffers to prevent memory issues
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Zero-buffer streaming headers
        if (strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false) {
            header('X-Accel-Buffering: no');
        }
        header('Cache-Control: no-cache');
        ini_set('output_buffering', 0);
        ini_set('zlib.output_compression', 0);
        ignore_user_abort(false);

        $disposition = 'inline';

        if (Flight::storage() instanceof S3Storage) {
            $this->streamFileFromS3($file, $disposition);
            exit;
        }

        if (Flight::storage() instanceof LocalStorage) {
            $this->streamFileFromLocal($file, $disposition);
            exit;
        }
        throw new \Exception('Unsupported storage type: ' . get_class(Flight::storage()));
    }

    /**
     * Streams a file directly from S3 to the client.
     * This avoids presigned URL expiration issues by using S3 credentials directly.
     *
     * @param UploadedFile $file The file to stream.
     * @param string $disposition Content disposition (attachment or inline).
     * @return void
     */
    private function streamFileFromS3(UploadedFile $file, string $disposition): void
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $storage = Flight::storage();
        if (!$storage instanceof S3Storage) {
            throw new \Exception('S3Storage required for streaming');
        }

        $key = Flight::storage()->getRelativePath($file->getUsername() . '/' . $file->filename);
        $s3Client = $storage->getS3Client();
        $bucket = $storage->getBucket();

        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            // Get file metadata first
            $headResult = $s3Client->headObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
            $totalLength = (int) $headResult['ContentLength'];
            $s3ContentType = $headResult['ContentType'] ?? null;
            $contentType = $s3ContentType ?? $file->mimetype;
            
            // Fix: Serve .mkv files as video/mp4 for browser streaming compatibility
            // Browsers don't natively support .mkv streaming but will stream .mp4
            if (strtolower($file->extension) === 'mkv') {
                $contentType = 'video/mp4';
                Flight::logger()->info('STREAM DEBUG: Overriding .mkv Content-Type to video/mp4 for streaming');
            }
            
            // Handle Range header for resumable downloads
            $range = Flight::request()->header('Range') ?? null;
            $startByte = 0;
            $endByte = $totalLength - 1;
            $isPartial = false;

            if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                $startByte = (int) $matches[1];
                $endByte = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $totalLength - 1;
                
                if ($startByte >= $totalLength || $startByte > $endByte) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $totalLength);
                    exit('Range Not Satisfiable');
                }

                $isPartial = true;
            } else {
                // No Range header - serve full file with HTTP 200
                // This ensures browsers complete full-file downloads instead of stopping after first chunk
                $isPartial = false;
                $startByte = 0;
                $endByte = $totalLength - 1;
            }

            $contentLength = $endByte - $startByte + 1;

            // Set all response headers here
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . $contentLength);
            header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($file->display_filename) . '"');
            header('Accept-Ranges: bytes');
            header('Cache-Control: public, max-age=31536000'); // Cache previews/images

            if ($isPartial) {
                http_response_code(206);
                header('Content-Range: bytes ' . $startByte . '-' . $endByte . '/' . $totalLength);
            }

            // Stream the object - use @http to enable true streaming (no buffering)
            $params = [
                'Bucket' => $bucket,
                'Key' => $key,
                '@http' => [
                    'stream' => true,
                ],
            ];
            if ($isPartial) {
                $params['Range'] = 'bytes=' . $startByte . '-' . $endByte;
            }

            try {
                $result = $s3Client->getObject($params);
                $stream = $result['Body'];
            } catch (\Aws\S3\Exception\S3Exception $e) {
                // If Range request fails (some S3 configurations), fall back to full file
                if ($startByte > 0 || $endByte < $totalLength - 1) {
                    Flight::logger()->warning('S3 Range request failed, falling back to full file: ' . $e->getMessage());
                    unset($params['Range']);
                    $result = $s3Client->getObject($params);
                    $stream = $result['Body'];
                } else {
                    throw $e;
                }
            }

            // Chunked streaming - large chunks for several seconds of video buffer
            $chunkSize = 8388608; // 8MB chunks for ~5-10 seconds of video buffer
            while (!$stream->eof() && $contentLength > 0) {
                $readSize = min($chunkSize, $contentLength);
                $chunk = $stream->read($readSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $contentLength -= strlen($chunk);
                // Flush after each chunk for immediate delivery
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                flush();
            }

            $stream->close();

        } catch (\Aws\S3\Exception\S3Exception $e) {
            Flight::logger()->error('S3 streaming error for key ' . $key . ': ' . $e->getMessage());
            http_response_code(404);
            exit('File not found');
        } catch (\Exception $e) {
            Flight::logger()->error('S3 streaming failed for key ' . $key . ': ' . $e->getMessage());
            http_response_code(500);
            exit('Download failed');
        }
    }

    /**
     * Streams a file from LocalStorage.
     */
    private function streamFileFromLocal(UploadedFile $file, string $disposition): void
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $key = $file->getUsername() . '/' . $file->filename;
        $totalLength = $file->filesize;

        // Clear output buffers to prevent corruption
        while (ob_get_level()) {
            ob_end_clean();
        }

        Flight::logger()->info('STREAM DEBUG LocalStorage: Starting stream for key=' . $key . ', filesize=' . $totalLength . ', disposition=' . $disposition);

        // Handle Range
        $range = Flight::request()->getHeader('Range') ?? null;
        $startByte = 0;
        $endByte = $totalLength - 1;
        $isPartial = false;

        $startByte = 0;
        $endByte = $totalLength - 1;
        $isPartial = false;

        if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            $startByte = (int) $matches[1];
            $endByte = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $totalLength - 1;
            
            if ($startByte >= $totalLength || $startByte > $endByte) {
                http_response_code(416);
                header('Content-Range: bytes */' . $totalLength);
                exit('Range Not Satisfiable');
            }
            $isPartial = true;
        } else {
            // No Range header - serve full file with HTTP 200
            // This ensures browsers complete full-file downloads instead of stopping after first chunk
            $isPartial = false;
            $startByte = 0;
            $endByte = $totalLength - 1;
        }

        $contentLength = $endByte - $startByte + 1;

        // Fix: Serve .mkv files as video/mp4 for browser streaming compatibility
        $contentType = $file->mimetype;
        if (strtolower($file->extension) === 'mkv') {
            $contentType = 'video/mp4';
            Flight::logger()->info('STREAM DEBUG: Overriding .mkv Content-Type to video/mp4 for streaming');
        }

        // Headers
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $contentLength);
        header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($file->display_filename) . '"');
        header('Accept-Ranges: bytes');

        if ($isPartial) {
            http_response_code(206);
            header('Content-Range: bytes ' . $startByte . '-' . $endByte . '/' . $totalLength);
        }

        $stream = Flight::storage()->getStream($key);
        if (!$stream || !is_resource($stream)) {
            http_response_code(500);
            exit('Stream error');
        }

        fseek($stream, $startByte);

        // Large chunk size for better buffer
        $chunkSize = 8388608; // 8MB chunks
        while ($contentLength > 0 && !feof($stream)) {
            $readSize = min($chunkSize, $contentLength);
            $chunk = fread($stream, $readSize);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $contentLength -= strlen($chunk);
            // Flush after each chunk for immediate delivery
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            Flight::logger()->debug('STREAM DEBUG LocalStorage: Sent chunk size=' . strlen($chunk) . ', remaining=' . $contentLength);
        }
        fclose($stream);
        Flight::logger()->info('STREAM DEBUG LocalStorage: Completed streaming key=' . $key);
    }

    /**
     * Uploads a file.
     *
     * @return void
     */
    public function uploadFile(): void
    {
        $uploads = Flight::request()->files['file'] ?? null;

        if (!$uploads) {
            Flight::halt(400, 'No file uploaded');
        }

        // Handle multiple files
        if (!is_array($uploads['name'])) {
            $uploads = [
                'name' => [$uploads['name']],
                'type' => [$uploads['type']],
                'tmp_name' => [$uploads['tmp_name']],
                'error' => [$uploads['error']],
                'size' => [$uploads['size']]
            ];
        }

        $results = [];

        foreach ($uploads['name'] as $index => $name) {
            if ($uploads['error'][$index] !== UPLOAD_ERR_OK) {
                $results[] = ['error' => 'Upload error for ' . $name];
                continue;
            }

            // Determine the path prefix based on user or voucher
            $user = Flight::user();
            $voucher = Flight::voucher();

            // Store file using storage abstraction
            $uniqueName = uniqid() . '_' . $name;
            $pathPrefix = $user ? $user->username : $voucher->getUsername();

            if (Flight::storage() instanceof S3Storage) {
                $presignedUrl = Flight::storage()->getPresignedPutUrl($pathPrefix . '/' . $uniqueName, $uploads['type'][$index], 3600 * 4);
                if (!$presignedUrl) {
                    $results[] = ['error' => 'Failed to generate presigned URL for ' . $name];
                    continue;
                }
                $results[] = [
                    'presigned_url' => $presignedUrl,
                    'filename' => $uniqueName,
                    'display_filename' => $name,
                    'filesize' => $uploads['size'][$index],
                    'mimetype' => $uploads['type'][$index],
                    'extension' => pathinfo($name, PATHINFO_EXTENSION),
                    'user_id' => $user->id ?? $voucher->user_id,
                    'voucher_id' => $voucher ? $voucher->id : null
                ];
                continue;
            }

            if (Flight::storage() instanceof LocalStorage) {
                if (!Flight::storage()->put($pathPrefix . '/' . $uniqueName, $uploads['tmp_name'][$index])) {
                    Flight::logger()->error('FileController uploadFile: failed to save file ' . $name);
                    $results[] = ['error' => 'Failed to save file ' . $name];
                    continue;
                }

                $file = new UploadedFile();
                $file->user_id = $user->id ?? $voucher->user_id;
                $file->voucher_id = $voucher->id ?? null;
                $file->filename = $uniqueName;
                $file->display_filename = $name;
                $file->filesize = $uploads['size'][$index];
                $file->mimetype = $uploads['type'][$index];
                $file->extension = pathinfo($name, PATHINFO_EXTENSION);

                if ($file->save()) {
                    $results[] = ['id' => $file->id, 'message' => 'File uploaded: ' . $name];
                } else {
                    $results[] = ['error' => 'Failed to save file record for ' . $name];
                }
            }
        }
        Flight::json($results);
    }

    /**
     * Updates a file.
     *
     * @param int $id The file ID.
     * @return void
     */
    public function updateFile($id): void
    {
        $file = UploadedFile::findById($id);

        if (!$file) {
            Flight::halt(404, 'File not found');
        }

        if (!Helper::can(FilePermission::UPDATE, $file)) {
            Flight::halt(403, 'Forbidden');
        }

        $data = Flight::request()->data;
        $file->display_filename = $data->display_filename ?? $file->display_filename;
        $file->notes = $data->notes ?? $file->notes;

        if ($file->save()) {
            Flight::json(['message' => 'File updated']);
        } else {
            Flight::halt(500, 'Failed to update file');
        }
    }

    /**
     * Deletes a file.
     *
     * @param int $id The file ID.
     * @return void
     */
    public function deleteFile($id): void
    {
        $file = UploadedFile::findById($id);

        if (!$file) {
            Flight::halt(404, 'File not found');
        }

        if (!Helper::can(FilePermission::DELETE, $file)) {
            Flight::halt(403, 'Forbidden');
        }

        Flight::storage()->delete($file->getUsername() . '/' . $file->filename);
        if ($file->delete()) {
            Flight::json(['message' => 'File deleted']);
        } else {
            Flight::halt(500, 'Failed to delete file');
        }
    }

    /**
     * Deletes multiple files.
     *
     * @return void
     */
    public function deleteMultipleFiles(): void
    {
        $data = Flight::request()->data;

        $ids = $data->ids ?? [];

        if (empty($ids) || !is_array($ids)) {
            Flight::json(['error' => 'Invalid or missing file IDs'], 400);
            return;
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $file = UploadedFile::findById($id);
            if (!$file) {
                $errors[] = "File $id not found";
                continue;
            }

            if (!Helper::can(FilePermission::DELETE, $file)) {
                $errors[] = "Forbidden to delete file $id";
                continue;
            }

            Flight::storage()->delete($file->getUsername() . '/' . $file->filename);
            if ($file->delete()) {
                $deleted++;
            } else {
                $errors[] = "Failed to delete file $id";
            }
        }
        Flight::json(['message' => "Deleted $deleted files", 'errors' => $errors]);
    }

    /**
     * Downloads multiple files as a zip.
     *
     * @return void
     */
    public function downloadMultipleFiles(): void
    {
        $data = Flight::request()->data;

        $ids = $data->ids ?? [];

        if (empty($ids) || !is_array($ids)) {
            Flight::halt(400, 'Invalid or missing file IDs');
        }

        $files = [];
        foreach ($ids as $id) {

            $file = UploadedFile::findById($id);
            if (!$file) {
                Flight::json(['error' => "File $id not found"], 404);
                return;
            }

            if (!Helper::can(FilePermission::GET, $file)) {
                Flight::json(['error' => "Forbidden to access file $id"], 403);
                return;
            }
            $files[] = $file;
        }

        $zipFilename = 'downloads_' . time() . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipFilename;

        $zipDir = dirname($zipPath);
        if (!is_writable($zipDir)) {
            Flight::logger()->error('Zip directory not writable: ' . $zipDir);
            Flight::json(['error' => 'Zip directory not writable'], 500);
            return;
        }

        // Create the zip
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            Flight::logger()->error('Failed to open zip at: ' . $zipPath);
            Flight::json(['error' => 'Failed to create zip file'], 500);
            return;
        }

        $tempFiles = [];

        foreach ($files as $file) {

            $filePath = $file->getUsername() . '/' . $file->filename;

            if (Flight::storage()->exists($filePath)) {

                $stream = Flight::storage()->getStream($filePath);

                if ($stream) {
                    // Create temp file to avoid memory exhaustion

                    $tempPath = tempnam(sys_get_temp_dir(), 'zip_download_');
                    $tempHandle = fopen($tempPath, 'w');

                    if (!$tempHandle) {
                        Flight::logger()->error('Failed to create temp file for: ' . $filePath);
                        continue;
                    }

                    if (is_resource($stream)) {
                        stream_copy_to_stream($stream, $tempHandle);
                        fclose($stream);
                    } elseif (method_exists($stream, 'detach')) {
                        // Psr7 stream, detach to get resource

                        $resource = $stream->detach();
                        if (is_resource($resource)) {
                            stream_copy_to_stream($resource, $tempHandle);
                            fclose($resource);
                        } else {
                            Flight::logger()->error('Could not detach stream for file: ' . $filePath);
                            fclose($tempHandle);
                            $tempFiles[] = $tempPath;
                            continue;
                        }
                    } elseif (method_exists($stream, 'getContents')) {
                        // Fallback, but may cause memory issues for large files
                        fwrite($tempHandle, $stream->getContents());
                    } else {
                        Flight::logger()->error('Unsupported stream type for file: ' . $filePath);
                        fclose($tempHandle);
                        $tempFiles[] = $tempPath;
                        continue;
                    }
                    fclose($tempHandle);

                    $zip->addFile($tempPath, $file->display_filename);

                    // Clean up temp file
                    $tempFiles[] = $tempPath;
                }
            }
        }

        if (!$zip->close()) {
            Flight::logger()->error('Failed to finalize zip file');
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            Flight::json(['error' => 'Failed to finalize zip file'], 500);
            return;
        }

        foreach ($tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        if (!file_exists($zipPath)) {
            Flight::json(['error' => 'Zip file was not created successfully'], 500);
            return;
        }

        // Stream the zip
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
        header('Content-Length: ' . filesize($zipPath));
        $stream = fopen($zipPath, 'rb');

        while (!feof($stream)) {
            echo fread($stream, 65536); // Send chunks
            flush();
        }

        fclose($stream);
        readfile($zipPath);
        unlink($zipPath); // Clean up
        exit;
    }

    /**
     * Generates a share token for a file.
     *
     * @param int $id The file ID.
     * @return void
     */
    public function generateShareToken($id): void
    {
        $file = UploadedFile::findById($id);

        if (!$file) {
            Flight::halt(404, 'File not found');
        }

        if (!Helper::can(FilePermission::GET, $file)) {
            Flight::halt(403, 'Forbidden');
        }

        $data = Flight::request()->data;
        $duration = $data->duration ?? '1h'; // default 1 hour

        // Calculate expires_at
        $expiresAt = null;
        switch ($duration) {
            case '1h':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                break;
            case '1d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
                break;
            case '1w':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 week'));
                break;
            case '1m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
                break;
            case '3m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 months'));
                break;
            case '6m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+6 months'));
                break;
            case '1y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                break;
            case '3y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 years'));
                break;
            case 'forever':
                $expiresAt = null;
                break;
            default:
                Flight::halt(400, 'Invalid duration');
        }

        // Generate unique token
        $token = bin2hex(random_bytes(32));

        $voucher = Flight::voucher();

        $fileToken = new UploadedFileToken();
        $fileToken->uploaded_file_id = $file->id;
        $fileToken->voucher_id = $voucher->id ?? null;
        $fileToken->token = $token;
        $fileToken->expires_at = $expiresAt;

        if ($fileToken->save()) {
            $shareUrl = Flight::request()->getScheme() . '://' . Flight::request()->servername . '/files/' . $file->id . '?token=' . $token;
            Flight::json(['share_url' => $shareUrl, 'expires_at' => $expiresAt]);
        } else {
            Flight::halt(500, 'Failed to generate share token');
        }
    }

    /**
     * Generates a batch share token for multiple files.
     *
     * @return void
     */
    public function generateBatchShareToken(): void
    {
        $data = Flight::request()->data;
        $ids = $data->ids ?? [];
        if (empty($ids) || !is_array($ids)) {
            Flight::halt(400, 'Invalid or missing file IDs');
        }

        $duration = $data->duration ?? '1h';

        // Calculate expires_at
        $expiresAt = null;
        switch ($duration) {
            case '1h':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                break;
            case '1d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
                break;
            case '1w':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 week'));
                break;
            case '1m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
                break;
            case '3m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 months'));
                break;
            case '6m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+6 months'));
                break;
            case '1y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                break;
            case '3y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 years'));
                break;
            case 'forever':
                $expiresAt = null;
                break;
            default:
                Flight::halt(400, 'Invalid duration');
        }

        // Validate files
        foreach ($ids as $id) {
            $file = UploadedFile::findById((int)$id);
            if (!$file || !Helper::can(FilePermission::GET, $file)) {
                Flight::halt(403, 'Forbidden or file not found: ' . $id);
            }
        }

        // Generate unique token
        $token = bin2hex(random_bytes(32));

        $voucher = Flight::voucher();

        $batchToken = new BatchFileToken();
        $batchToken->voucher_id = $voucher->id ?? null;
        $batchToken->file_ids = $ids;
        $batchToken->token = $token;
        $batchToken->expires_at = $expiresAt;

        if ($batchToken->save()) {
            $shareUrl = Flight::request()->getScheme() . '://' . Flight::request()->servername . '/files/batch/' . $token;
            Flight::json(['share_url' => $shareUrl, 'expires_at' => $expiresAt]);
        } else {
            Flight::halt(500, 'Failed to generate batch share token');
        }
    }

    /**
     * Shows a batch share page.
     *
     * @param string $token The batch token.
     * @return void
     */
    public function showBatchShare($token): void
    {
        $batchToken = BatchFileToken::findByToken($token);
        if (!$batchToken) {
            Flight::halt(404, 'Batch share not found or expired');
        }

        $files = [];
        foreach ($batchToken->file_ids as $id) {
            $file = UploadedFile::findById((int)$id);
            if ($file) {
                $files[] = $file;
            }
        }

        Flight::render('batch-share.latte', [
            'batchToken' => $batchToken,
            'files' => $files
        ]);
    }

    /**
     * Gets a thumbnail for a file.
     *
     * @param int $id The file ID.
     * @return void
     */
    public function getThumbnail($id): void
    {
        try {
            $file = UploadedFile::findById($id);

            if (!$file) {
                Flight::logger()->error('getThumbnail: File not found for ID: ' . $id);
                Flight::halt(404, 'File not found');
            }
            
            if (!Helper::can(FilePermission::VIEW, $file)) {
                Flight::logger()->warning('getThumbnail: Forbidden access for file ID: ' . $id);
                Flight::halt(403, 'Forbidden');
            }
            
            if (Flight::storage() instanceof S3Storage) {
                $presignedUrl = Flight::storage()->getUrl($file->getUsername() . '/' . $file->filename);
                Flight::redirect($presignedUrl, 301);
                exit;
            }
            
            if (Flight::storage() instanceof LocalStorage) {
                $response = Flight::response();
                $response->header('Content-Type', $file->mimetype);
                $response->header('Content-Length', $file->filesize);
                $stream = Flight::storage()->getStream($file->getUsername() . '/' . $file->filename);
                $response->send(fpassthru($stream));
                fclose($stream);
                exit;
            }
            throw new \Exception('Unsupported storage type: ' . get_class(Flight::storage()));
        } catch (\Exception $e) {
            Flight::logger()->error('getThumbnail: Exception: ' . $e->getMessage());
            Flight::halt(500, 'Internal server error');
        }
    }

    /**
     * Requests a presigned URL for upload.
     *
     * @return void
     */
    public function requestPresignedUrl(): void
    {
        $data = Flight::request()->data;
        $filename = $data->filename ?? null;
        $filesize = $data->filesize ?? null;
        $mimetype = $data->mimetype ?? null;

        if (!$filename || !$filesize || !$mimetype) {
            Flight::halt(400, 'Missing required fields: filename, filesize, mimetype');
        }

        $user = Flight::user();
        $voucher = Flight::voucher();

        $storage = Flight::storage();

        if ($storage instanceof S3Storage) {
            $uniqueName = uniqid() . '_' . $filename;
            $pathPrefix = $user ? $user->username : $voucher->getUsername();
            $s3Key = $pathPrefix . '/' . $uniqueName;
            
            Flight::logger()->info('requestPresignedUrl: generating presigned URL for key=' . $s3Key);
            $presignedUrl = $storage->getPresignedPutUrl($s3Key, $mimetype, 3600 * 4);
            
            if (!$presignedUrl) {
                Flight::logger()->error('requestPresignedUrl: FAILED to generate presigned URL for key=' . $s3Key);
                Flight::halt(500, 'Failed to generate presigned URL');
            }
            
            Flight::logger()->info('requestPresignedUrl: SUCCESS generated presigned URL');
            Flight::json([
                'presigned_url' => $presignedUrl,
                'filename' => $uniqueName,
                'display_filename' => $filename,
                'filesize' => $filesize,
                'mimetype' => $mimetype,
                'extension' => pathinfo($filename, PATHINFO_EXTENSION),
                'user_id' => $user->id ?? $voucher->user_id,
                'voucher_id' => $voucher ? $voucher->id : null
            ]);
        } else {
            // For local storage, no presigned URL needed, just return metadata
            Flight::json([
                'filename' => $filename,
                'display_filename' => $filename,
                'filesize' => $filesize,
                'mimetype' => $mimetype,
                'extension' => pathinfo($filename, PATHINFO_EXTENSION),
                'user_id' => $user->id ?? $voucher->user_id,
                'voucher_id' => $voucher ? $voucher->id : null
            ]);
        }
    }

    /**
     * Registers an uploaded file.
     *
     * @return void
     */
    public function registerUploadedFile(): void
    {
        $data = Flight::request()->data;
        
        $filename = $data->filename ?? null;
        $displayFilename = $data->display_filename ?? null;
        $filesize = $data->filesize ?? null;
        $mimetype = $data->mimetype ?? null;
        $extension = $data->extension ?? null;

        if (!$filename || !$displayFilename || !$filesize || !$mimetype) {
            Flight::halt(400, 'Missing required fields');
        }

        $user = Flight::user();
        $voucher = Flight::voucher();

        $pathPrefix = $user ? $user->username : $voucher->getUsername();

        // Verify the file exists in storage
        if (!Flight::storage()->exists($pathPrefix . '/' . $filename)) {
            Flight::halt(400, 'File not found in storage');
        }

        $file = new UploadedFile();
        $file->user_id = $user->id ?? $voucher->user_id;
        $file->voucher_id = $voucher->id ?? null;
        $file->filename = $filename;
        $file->display_filename = $displayFilename;
        $file->filesize = $filesize;
        $file->mimetype = $mimetype;
        $file->extension = $extension;

        if ($file->save()) {
            Flight::json(['id' => $file->id, 'message' => 'File registered: ' . $displayFilename]);
        } else {
            Flight::halt(500, 'Failed to register file');
        }
    }

    /**
     * Gets a preview for a file.
     *
     * @param int $id The file ID.
     * @return void
     */
    public function getPreview($id): void
    {
        $file = UploadedFile::findById($id);

        if (!$file) {
            Flight::halt(404, 'File not found');
        }

        if (!Helper::can(FilePermission::VIEW, $file)) {
            Flight::halt(403, 'Forbidden');
        }

        if (Flight::storage() instanceof S3Storage) {
            $presignedUrl = Flight::storage()->getUrl($file->getUsername() . '/' . $file->filename);
            Flight::redirect($presignedUrl, 301);
            exit;
        }

        if (Flight::storage() instanceof LocalStorage) {
            $response = Flight::response();
            $response->header('Content-Type', $file->mimetype);
            $response->header('Content-Length', $file->filesize);
            $stream = Flight::storage()->getStream($file->getUsername() . '/' . $file->filename);
            $response->send(fpassthru($stream));
            fclose($stream);
            exit;
        }

        throw new \Exception('Unsupported storage type: ' . get_class(Flight::storage()));
    }

    /**
     * Displays the duplicates page.
     *
     * @return void
     */
    public function duplicates(): void
    {
        $user = Flight::user();

        $page = (int)(Flight::request()->query['page'] ?? 1);
        $limit = (int)(Flight::request()->query['limit'] ?? 10);
        $offset = ($page - 1) * $limit;

        $result = $this->findDuplicates($user->id, $limit, $offset);
        $total = $result['total'];
        $totalPages = ceil($total / $limit);

        Flight::render('duplicates.latte', [
            'duplicates' => $result['duplicates'],
            'user' => $user,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'limit' => $limit,
            'totalDuplicateSize' => $result['totalDuplicateSize'],
            'totalSpaceSaved' => $result['totalSpaceSaved']
        ]);
    }

    /**
     * Finds duplicate files for a user.
     *
     * @param int $userId The user ID.
     * @param int $limit The limit.
     * @param int $offset The offset.
     * @return array The duplicates data.
     */
    private function findDuplicates(int $userId, int $limit = 10, int $offset = 0): array
    {
        $db = Flight::db();

        // Get files that have duplicates (filesize and mimetype combination appears more than once)
        $stmt = $db->prepare('
            SELECT * FROM uploaded_files
            WHERE user_id = ?
            AND CONCAT(filesize, \'|\', mimetype) IN (
                SELECT CONCAT(filesize, \'|\', mimetype)
                FROM uploaded_files
                WHERE user_id = ?
                GROUP BY filesize, mimetype
                HAVING COUNT(*) > 1
            )
        ');
        $stmt->execute([$userId, $userId]);
        $files = [];

        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $files[] = new UploadedFile($data);
        }

        // Group by size and mimetype
        $groups = [];
        foreach ($files as $file) {
            $key = $file->filesize . '|' . $file->mimetype;
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $file;
        }

        $duplicates = [];
        foreach ($groups as $group) {
            if (count($group) > 1) {
                // Sort by filename length (shorter first, likely original), then by uploaded_at oldest first, then by id asc
                usort($group, function ($a, $b) {
                    $lenA = strlen($a->display_filename);
                    $lenB = strlen($b->display_filename);
                    if ($lenA !== $lenB) {
                        return $lenA <=> $lenB;
                    }
                    $timeA = strtotime($a->uploaded_at);
                    $timeB = strtotime($b->uploaded_at);
                    if ($timeA !== $timeB) {
                        return $timeA <=> $timeB;
                    }
                    return $a->id <=> $b->id;
                });
                $duplicates[] = $group;
            }
        }

        // now filter out files that do not exist on the storage (ie: orphaned records)
        $checked = 50;
        $duplicates = array_filter($duplicates, function ($group) use(&$checked) {
            if ($checked-- === 0) {
                return false;
            }
            return count($group) > 1 && Flight::storage()->exists($group[0]->getUsername() . '/' . $group[0]->filename);
        });

        // Sort groups by total size descending (largest space savings first)
        usort($duplicates, function ($a, $b) {
            $sizeA = count($a) * $a[0]->filesize;
            $sizeB = count($b) * $b[0]->filesize;
            return $sizeB <=> $sizeA;
        });

        $totalGroups = count($duplicates);
        $totalDuplicateSize = 0;
        $totalSpaceSaved = 0;

        foreach ($duplicates as $group) {
            $count = count($group);
            $size = $group[0]->filesize;
            $totalDuplicateSize += $count * $size;
            $totalSpaceSaved += ($count - 1) * $size;
        }

        $paginated = array_slice($duplicates, $offset, $limit);

        return [
            'duplicates' => $paginated,
            'total' => $totalGroups,
            'totalDuplicateSize' => $totalDuplicateSize,
            'totalSpaceSaved' => $totalSpaceSaved
        ];
    }

    /**
     * Computes the hash of a file.
     *
     * @param UploadedFile $file The file.
     * @param bool $full Whether to hash the full file.
     * @return string The hash.
     */
    private function computeFileHash(UploadedFile $file, bool $full = false): string
    {
        $path = $file->getUsername() . '/' . $file->filename;
        if (!Flight::storage()->exists($path)) {
            return '';
        }

        $stream = Flight::storage()->getStream($path);
        if (!$stream) {
            return '';
        }

        $hash = hash_init('sha256');
        $bytesRead = 0;
        $maxBytes = $full ? PHP_INT_MAX : 100 * 1024; // 100KB or full

        if (is_resource($stream)) {
            // LocalStorage: resource
            while (!feof($stream) && $bytesRead < $maxBytes) {
                $chunkSize = min(8192, $maxBytes - $bytesRead);
                $data = fread($stream, $chunkSize);
                hash_update($hash, $data);
                $bytesRead += strlen($data);
            }
            fclose($stream);
        } elseif (method_exists($stream, 'read')) {
            // S3: Psr7 Stream

            while (!$stream->eof() && $bytesRead < $maxBytes) {
                $chunkSize = min(8192, $maxBytes - $bytesRead);
                $data = $stream->read($chunkSize);
                hash_update($hash, $data);
                $bytesRead += strlen($data);
            }
            $stream->close();
        } else {
            return '';
        }

        return hash_final($hash);
    }

    /**
     * Deletes duplicate files.
     *
     * @return void
     */
    public function deleteDuplicates(): void
    {
        $user = Flight::user();

        $data = Flight::request()->data;
        $idsToDelete = $data->ids ?? [];
        
        if (empty($idsToDelete) || !is_array($idsToDelete)) {
            Flight::json(['error' => 'Invalid file IDs'], 400);
            return;
        }

        $deleted = 0;
        $errors = [];
        foreach ($idsToDelete as $id) {
            $file = UploadedFile::findById($id);
            if (!$file || $file->user_id !== $user->id) {
                $errors[] = "File $id not found or not owned by user";
                continue;
            }

            $storagePath = $file->getUsername() . '/' . $file->filename;
            if (!Flight::storage()->delete($storagePath)) {
                $errors[] = "Failed to delete file $id from storage";
                continue;
            }

            if ($file->delete()) {
                $deleted++;
            } else {
                $errors[] = "Failed to delete file $id from database";
            }
        }
        Flight::json(['message' => "Deleted $deleted duplicate files", 'errors' => $errors]);
    }

    /**
     * Verifies if files are duplicates.
     *
     * @return void
     */
    public function verifyDuplicates(): void
    {
        $user = Flight::user();

        $data = Flight::request()->data;
        $ids = $data->ids ?? [];
        
        if (empty($ids) || !is_array($ids)) {
            Flight::json(['error' => 'Invalid file IDs'], 400);
            return;
        }

        $files = [];
        foreach ($ids as $id) {
            $file = UploadedFile::findById($id);
            if (!$file || $file->user_id !== $user->id) {
                Flight::json(['error' => 'File not found or not owned'], 404);
                return;
            }
            $files[] = $file;
        }

        // First check 100KB
        $hashes100 = [];
        foreach ($files as $file) {
            $hash = $this->computeFileHash($file, false);
            $hashes100[] = $hash;
        }
        $identical100 = count(array_unique($hashes100)) === 1;

        $identicalFull = false;
        $hashesFull = [];
        if ($identical100) {
            // If 100KB identical, check full
            foreach ($files as $file) {
                $hash = $this->computeFileHash($file, true);
                $hashesFull[] = $hash;
            }
            $identicalFull = count(array_unique($hashesFull)) === 1;
        }

        Flight::json([
            'identical_100kb' => $identical100,
            'identical_full' => $identicalFull,
            'hashes_100kb' => $hashes100,
            'hashes_full' => $hashesFull
        ]);
    }

    /**
     * Shortens a URL.
     *
     * @return void
     */
    public function shortenUrl(): void
    {
        $data = Flight::request()->data;
        $target = $data->target ?? null;
        if (!$target) {
            Flight::halt(400, 'Missing target URL');
        }

        $duration = $data->duration ?? '1h';

        // Calculate expires_at
        $expiresAt = null;
        switch ($duration) {
            case '1h':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                break;
            case '1d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
                break;
            case '1w':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 week'));
                break;
            case '1m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
                break;
            case '3m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 months'));
                break;
            case '6m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+6 months'));
                break;
            case '1y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                break;
            case '3y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 years'));
                break;
            case 'forever':
                $expiresAt = null;
                break;
            default:
                Flight::halt(400, 'Invalid duration');
        }

        $shortLink = new ShortLink();
        $shortLink->target = $target;
        $shortLink->expires_at = $expiresAt;

        if ($shortLink->save()) {
            $shortUrl = Flight::request()->getScheme() . '://' . Flight::request()->servername . '/s/' . $shortLink->shortcode;
            Flight::json(['short_url' => $shortUrl, 'expires_at' => $expiresAt]);
        } else {
            Flight::halt(500, 'Failed to create short link');
        }
    }

    /**
     * Redirects short link to target.
     *
     * @param string $shortcode The shortcode.
     * @return void
     */
    public function redirectShortLink($shortcode): void
    {
        $shortLink = ShortLink::findByShortcode($shortcode);
        if (!$shortLink) {
            Flight::halt(404, 'Short link not found');
        }

        Flight::redirect($shortLink->target);
    }
}
