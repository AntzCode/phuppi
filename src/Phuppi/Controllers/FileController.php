<?php

namespace Phuppi\Controllers;

use Flight;
use Phuppi\User;
use Phuppi\Voucher;
use Phuppi\UploadedFile;
use Phuppi\UploadedFileToken;
use Phuppi\PermissionChecker;

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
        Flight::logger()->info('FileController index called with session ID: ' . $sessionId . ', last activity: ' . ($lastActivity ? date('Y-m-d H:i:s', $lastActivity) : 'none'));
        $files = UploadedFile::findByUser($sessionId);
        Flight::logger()->info('Files loaded: ' . count($files));
        Flight::render('home.latte', ['files' => $files, 'name' => 'Phuppi!', 'sessionId' => $sessionId]);
        Flight::logger()->info('Render completed');
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

    private function getPermissionChecker(): PermissionChecker
    {
        $user = $this->getCurrentUser();
        $voucher = $this->getCurrentVoucher();
        if (!$user && !$voucher) {
            Flight::halt(403, 'Unauthorized');
        }
        return new PermissionChecker($user, $voucher);
    }

    public function listFiles()
    {
        Flight::logger()->info('listFiles called with session ID: ' . (Flight::session()->get('id') ?? 'none'));
        $checker = $this->getPermissionChecker();
        if (!$checker->canListFiles()) {
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
        Flight::logger()->info('listFiles: returning ' . count($result['files']) . ' files out of ' . $total . ' total');

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
        if (!$file) {
            Flight::halt(404, 'File not found');
        }

        // Check for token-based access
        $token = Flight::request()->query['token'] ?? null;
        if ($token) {
            $fileToken = UploadedFileToken::findByToken($token);
            if (!$fileToken || $fileToken->uploaded_file_id != $id) {
                Flight::halt(403, 'Invalid or expired token');
            }
        } else {
            $checker = $this->getPermissionChecker();
            if (!$checker->canGetFile($file)) {
                Flight::halt(403, 'Forbidden');
            }
        }

        $filePath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file->filename;
        if (!file_exists($filePath)) {
            Flight::halt(404, 'File not found on disk');
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
        header('Content-Length: ' . filesize($filePath));
        // Stream the file
        $fp = fopen($filePath, 'rb');
        fpassthru($fp);
        fclose($fp);
        exit;
    }

    public function uploadFile()
    {
        $checker = $this->getPermissionChecker();
        if (!$checker->canPutFile()) {
            Flight::halt(403, 'Forbidden');
        }
        $upload = Flight::request()->files['file'] ?? null;
        if (!$upload) {
            Flight::halt(400, 'No file uploaded');
        }
        // Move file to uploads/
        $filename = uniqid() . '_' . $upload['name'];
        $path = 'uploads/' . $filename;
        if (!move_uploaded_file($upload['tmp_name'], $path)) {
            Flight::halt(500, 'Failed to save file');
        }
        $file = new UploadedFile();
        $file->user_id = $this->getCurrentUser() ? $this->getCurrentUser()->id : null;
        $file->voucher_id = $this->getCurrentVoucher() ? $this->getCurrentVoucher()->id : null;
        $file->filename = $filename;
        $file->display_filename = $upload['name'];
        $file->filesize = $upload['size'];
        $file->mimetype = $upload['type'];
        $file->extension = pathinfo($upload['name'], PATHINFO_EXTENSION);
        if ($file->save()) {
            Flight::json(['id' => $file->id, 'message' => 'File uploaded']);
        } else {
            Flight::halt(500, 'Failed to save file record');
        }
    }

    public function updateFile($id)
    {
        $file = UploadedFile::findById($id);
        if (!$file) {
            Flight::halt(404, 'File not found');
        }
        $checker = $this->getPermissionChecker();
        if (!$checker->canUpdateFile($file)) {
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
        $checker = $this->getPermissionChecker();
        if (!$checker->canDeleteFile($file)) {
            Flight::halt(403, 'Forbidden');
        }
        $filePath = 'uploads/' . $file->filename;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
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
        $checker = $this->getPermissionChecker();
        $deleted = 0;
        $errors = [];
        foreach ($ids as $id) {
            $file = UploadedFile::findById($id);
            if (!$file) {
                $errors[] = "File $id not found";
                continue;
            }
            if (!$checker->canDeleteFile($file)) {
                $errors[] = "Forbidden to delete file $id";
                continue;
            }
            $filePath = 'uploads/' . $file->filename;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
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
        $checker = $this->getPermissionChecker();
        $files = [];
        foreach ($ids as $id) {
            $file = UploadedFile::findById($id);
            if (!$file) {
                Flight::json(['error' => "File $id not found"], 404);
                return;
            }
            if (!$checker->canGetFile($file)) {
                Flight::json(['error' => "Forbidden to access file $id"], 403);
                return;
            }
            $files[] = $file;
        }

        // Create zip
        $zip = new \ZipArchive();
        $zipFilename = 'downloads_' . time() . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipFilename;
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== TRUE) {
            Flight::json(['error' => 'Failed to create zip file'], 500);
            return;
        }

        foreach ($files as $file) {
            $filePath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file->filename;
            if (file_exists($filePath)) {
                $zip->addFile($filePath, $file->display_filename);
            }
        }
        if (!$zip->close()) {
            Flight::json(['error' => 'Failed to finalize zip file'], 500);
            return;
        }

        if (!file_exists($zipPath)) {
            Flight::json(['error' => 'Zip file was not created successfully'], 500);
            return;
        }

        // Stream the zip
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
        header('Content-Length: ' . filesize($zipPath));
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
        $checker = $this->getPermissionChecker();
        if (!$checker->canGetFile($file)) {
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
        $file = UploadedFile::findById($id);
        if (!$file) {
            Flight::halt(404, 'File not found');
        }
        $checker = $this->getPermissionChecker();
        if (!$checker->canGetFile($file)) {
            Flight::halt(403, 'Forbidden');
        }
        $filePath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file->filename;
        if (!file_exists($filePath)) {
            Flight::halt(404, 'File not found on disk: ' . $filePath);
        }
        Flight::response()->header('Content-Type', $file->mimetype);
        Flight::response()->header('Content-Disposition', 'attachment; filename="' . $file->display_filename . '"');
        readfile($filePath);
    }
    public function getPreview($id)
    {
        $file = UploadedFile::findById($id);
        if (!$file) {
            Flight::halt(404, 'File not found');
        }
        $checker = $this->getPermissionChecker();
        if (!$checker->canGetFile($file)) {
            Flight::halt(403, 'Forbidden');
        }
        $filePath = Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file->filename;
        if (!file_exists($filePath)) {
            Flight::halt(404, 'File not found on disk: ' . $filePath);
        }
        Flight::response()->header('Content-Type', $file->mimetype);
        Flight::response()->header('Content-Disposition', 'attachment; filename="' . $file->display_filename . '"');
        readfile($filePath);
    }
}
