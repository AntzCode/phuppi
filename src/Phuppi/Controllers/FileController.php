<?php

namespace Phuppi\Controllers;

use Flight;
use Phuppi\User;
use Phuppi\Voucher;
use Phuppi\UploadedFile;
use Phuppi\UploadedFileToken;
use Phuppi\Permissions\FilePermission;
use Phuppi\Storage\LocalStorage;
use Phuppi\Storage\S3Storage;

class FileController
{

    public function index()
    {
        $sessionId = Flight::session()->get('id');
        $lastActivity = Flight::session()->get('last_activity');
        if (!$sessionId) {
            Flight::logger()->warning('Session ID missing, redirecting to login');
            Flight::redirect('/login');
        }
        // Check for session expiration (30 minutes)
        $sessionTimeout = 30 * 60; // 30 minutes
        if ($lastActivity && (time() - $lastActivity) > $sessionTimeout) {
            Flight::logger()->warning('Session expired due to inactivity, destroying session. Last activity: ' . date('Y-m-d H:i:s', $lastActivity));
            Flight::session()->clear();
            Flight::redirect('/login');
        }
        $files = UploadedFile::findByUser($sessionId);
        Flight::render('home.latte', ['files' => $files, 'name' => 'Phuppi!', 'sessionId' => $sessionId]);
    }

    private function getCurrentUser(): ?User
    {
        $sessionId = Flight::session()->get('id');
        if ($sessionId) {
            return User::findById($sessionId);
        }
        return null;
    }

    private function getCurrentVoucher(): ?Voucher
    {
        // Assume voucher code is passed in header or param
        $voucherCode = Flight::request()->headers['X-Voucher-Code'] ?? Flight::request()->query['voucher'];
        if ($voucherCode) {
            $voucher = Voucher::findByCode($voucherCode);
            if ($voucher && !$voucher->isExpired() && !$voucher->isDeleted()) {
                return $voucher;
            }
        }
        return null;
    }

    public function listFiles()
    {
        
        if (!Flight::user()->can(FilePermission::LIST)) {
            Flight::logger()->warning('listFiles: Forbidden access');
            Flight::halt(403, 'Forbidden');
        }

        // Get query parameters
        $keyword = Flight::request()->query['keyword'] ?? '';
        $sort = Flight::request()->query['sort'] ?? 'date_newest';
        $page = (int)(Flight::request()->query['page'] ?? 1);
        $limit = (int)(Flight::request()->query['limit'] ?? 10);
        $offset = ($page - 1) * $limit;

        $user = $this->getCurrentUser();
        $voucher = $this->getCurrentVoucher();

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
                'notes' => $file->notes
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

    public function getFile($id)
    {
        $file = UploadedFile::findById($id);

        if(!Flight::user()->hasRole('admin') && Flight::user()->id !== $file->user_id) {
            // Only an admin or the file's owner can view their own file without a token

            $token = Flight::request()->query['token'] ?? null;
            if(!$token) {
                Flight::halt(403, 'Token required');
            }
            if(strlen($token) > 255) {
                Flight::halt(413, 'Invalid token');
            }
            if (!$file) {
                Flight::halt(403, 'Invalid or expired token');
            }
            $fileToken = UploadedFileToken::findByToken($token);
            if (!$fileToken || $fileToken->uploaded_file_id != $id) {
                Flight::halt(403, 'Invalid or expired token');
            }
        }

        if (!Flight::storage()->exists($file->getUsername() . '/' . $file->filename)) {
            Flight::halt(404, 'File not found');
        }

        // Clear any output buffers to prevent memory issues
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers
        header('Content-Type: ' . $file->mimetype);
        $forceDownload = Flight::request()->query['download'] ?? false;
        $disposition = $forceDownload || !(str_starts_with($file->mimetype, 'image/') || str_starts_with($file->mimetype, 'video/')) ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $disposition . '; filename="' . $file->display_filename . '"');

        if(Flight::storage() instanceof S3Storage) {
            // calculate a token that expires after download - expect 1 GB per hour download speed
            $expiresIn = ceil(3600 * ($file->filesize / 1024 / 1024 / 1024) );
            
            // min 15 seconds
            $expiresIn = $expiresIn < 15 ? 15 : $expiresIn;

            if($forceDownload) {
                $presignedUrl = Flight::storage()->getUrl($file->getUsername() . '/' . $file->filename, $expiresIn, [
                    'ResponseContentDisposition' => $disposition . '; filename="' . $file->display_filename . '"'
                ]);
            } else {
                $presignedUrl = Flight::storage()->getUrl($file->getUsername() . '/' . $file->filename, $expiresIn);
            }
            Flight::redirect($presignedUrl, 301);
            exit;
        }

        if(Flight::storage() instanceof LocalStorage) {
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

    public function uploadFile()
    {
        if (!Flight::user()->can(FilePermission::PUT)) {
            Flight::halt(403, 'Forbidden');
        }
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
            $user = $this->getCurrentUser();
            $voucher = $this->getCurrentVoucher();
            // Store file using storage abstraction
            $uniqueName = uniqid() . '_' . $name;
            if(Flight::storage() instanceof S3Storage) {
                $presignedUrl = Flight::storage()->getPresignedPutUrl($user->username . '/' . $uniqueName, $uploads['type'][$index]);
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
                    'user_id' => $user ? $user->id : null,
                    'voucher_id' => $voucher ? $voucher->id : null
                ];
                continue;
            }

            if(Flight::storage() instanceof LocalStorage) {
                if (!Flight::storage()->put($user->username . '/' . $uniqueName, $uploads['tmp_name'][$index])) {
                    Flight::logger()->error('FileController uploadFile: failed to save file ' . $name);
                    $results[] = ['error' => 'Failed to save file ' . $name];
                    continue;
                }
            }
            
            
            
            $file = new UploadedFile();
            $file->user_id = $user ? $user->id : null;
            $file->voucher_id = $voucher ? $voucher->id : null;
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
        Flight::json($results);
    }

    public function updateFile($id)
    {
        $file = UploadedFile::findById($id);
        if (!$file) {
            Flight::halt(404, 'File not found');
        }
        if (!Flight::user()->can(FilePermission::UPDATE, $file)) {
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

    public function deleteFile($id)
    {
        $file = UploadedFile::findById($id);
        if (!$file) {
            Flight::halt(404, 'File not found');
        }
        if (!Flight::user()->can(FilePermission::DELETE, $file)) {
            Flight::halt(403, 'Forbidden');
        }
        Flight::storage()->delete($file->getUsername() . '/' . $file->filename);
        if ($file->delete()) {
            Flight::json(['message' => 'File deleted']);
        } else {
            Flight::halt(500, 'Failed to delete file');
        }
    }

    public function deleteMultipleFiles()
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
            if (!Flight::user()->can(FilePermission::DELETE, $file)) {
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

    public function downloadMultipleFiles()
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
            if (!Flight::user()->can(FilePermission::GET, $file)) {
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

    public function generateShareToken($id)
    {
        $file = UploadedFile::findById($id);
        if (!$file) {
            Flight::halt(404, 'File not found');
        }
        if (!Flight::user()->can(FilePermission::GET, $file)) {
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

        $fileToken = new UploadedFileToken();
        $fileToken->uploaded_file_id = $file->id;
        $fileToken->voucher_id = $this->getCurrentVoucher() ? $this->getCurrentVoucher()->id : null;
        $fileToken->token = $token;
        $fileToken->expires_at = $expiresAt;

        if ($fileToken->save()) {
            $shareUrl = Flight::request()->getScheme() . '://' . Flight::request()->servername . '/files/' . $file->id . '?token=' . $token;
            Flight::json(['share_url' => $shareUrl, 'expires_at' => $expiresAt]);
        } else {
            Flight::halt(500, 'Failed to generate share token');
        }
    }

    public function getThumbnail($id)
    {
        
        try {
            $file = UploadedFile::findById($id);
            if (!$file) {
                Flight::logger()->error('getThumbnail: File not found for ID: ' . $id);
                Flight::halt(404, 'File not found');
            }
            if (!Flight::user()->can(FilePermission::VIEW, $file)) {
                Flight::logger()->warning('getThumbnail: Forbidden access for file ID: ' . $id);
                Flight::halt(403, 'Forbidden');
            }
            if(Flight::storage() instanceof S3Storage) {
                $presignedUrl = Flight::storage()->getUrl($file->getUsername() . '/' . $file->filename);
                Flight::redirect($presignedUrl, 301);
            }
            if(Flight::storage() instanceof LocalStorage) {
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
    public function registerUploadedFile()
    {
        if (!Flight::user()->can(FilePermission::PUT)) {
            Flight::halt(403, 'Forbidden');
        }
        $data = Flight::request()->data;
        $filename = $data->filename ?? null;
        $displayFilename = $data->display_filename ?? null;
        $filesize = $data->filesize ?? null;
        $mimetype = $data->mimetype ?? null;
        $extension = $data->extension ?? null;
        $userId = $data->user_id ?? null;
        $voucherId = $data->voucher_id ?? null;

        if (!$filename || !$displayFilename || !$filesize || !$mimetype) {
            Flight::halt(400, 'Missing required fields');
        }

        // Verify the file exists in storage
        if (!Flight::storage()->exists(Flight::user()->username . '/' . $filename)) {
            Flight::halt(400, 'File not found in storage');
        }

        $file = new UploadedFile();
        $file->user_id = $userId;
        $file->voucher_id = $voucherId;
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

    public function getPreview($id)
    {
        $file = UploadedFile::findById($id);
        if (!$file) {
            Flight::halt(404, 'File not found');
        }
        if (!Flight::user()->can(FilePermission::VIEW, $file)) {
            Flight::halt(403, 'Forbidden');
        }

        if(Flight::storage() instanceof S3Storage) {
            $presignedUrl = Flight::storage()->getUrl($file->getUsername() . '/' . $file->filename);
            Flight::redirect($presignedUrl, 301);
            exit;
        }

        if(Flight::storage() instanceof LocalStorage) {
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

    public function duplicates()
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            Flight::logger()->warning('duplicates: User not logged in');
            Flight::redirect('/login');
        }
        // Vouchers not allowed
        if ($this->getCurrentVoucher()) {
            Flight::halt(403, 'Forbidden for vouchers');
        }

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

    private function findDuplicates(int $userId, int $limit = 10, int $offset = 0): array
    {
        $db = Flight::db();
        // Get all files for user
        $stmt = $db->prepare('SELECT * FROM uploaded_files WHERE user_id = ?');
        $stmt->execute([$userId]);
        $files = [];
        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $files[] = new UploadedFile($data);
        }

        // Filter out orphaned records (files not existing in storage)
        $files = array_filter($files, function($file) {
            return Flight::storage()->exists($file->getUsername() . '/' . $file->filename);
        });

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
                usort($group, function($a, $b) {
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

        // Sort groups by total size descending (largest space savings first)
        usort($duplicates, function($a, $b) {
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

    public function deleteDuplicates()
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            Flight::halt(403, 'Forbidden');
        }
        if ($this->getCurrentVoucher()) {
            Flight::halt(403, 'Forbidden for vouchers');
        }

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

    public function verifyDuplicates()
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            Flight::halt(403, 'Forbidden');
        }
        if ($this->getCurrentVoucher()) {
            Flight::halt(403, 'Forbidden for vouchers');
        }

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
}
