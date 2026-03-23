<?php

/**
 * P2PController.php
 *
 * P2PController class for handling P2P file sharing operations in the Phuppi application.
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 * @since 2.0.0
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\P2PConnection;
use Phuppi\P2PShareToken;

class P2PController
{
    /**
     * Maximum number of PIN attempts before lockout.
     */
    private const MAX_PIN_ATTEMPTS = 3;

    /**
     * Lockout duration in minutes.
     */
    private const LOCKOUT_MINUTES = 15;

    /**
     * Renders the P2P sender page.
     *
     * GET /p2p
     * Requires authentication.
     *
     * @return void
     */
    public function index(): void
    {
        // Load active sessions for the current user
        $user = Flight::user();
        $sessions = [];
        
        if ($user && $user->id) {
            $tokens = P2PShareToken::findActiveByUserId($user->id);
            
            foreach ($tokens as $token) {
                $files = $token->getFilesMetadata();
                $fileCount = count($files);
                $totalSize = $token->getTotalSize();
                $connectionCount = 0;
                
                $connectionCount = P2PConnection::countActiveByTokenId($token->id);
                
                $sessions[] = [
                    'token' => $token->token,
                    'shortcode' => $token->shortcode,
                    'pin' => $token->pin,
                    'files' => $files,
                    'fileCount' => $fileCount,
                    'totalSize' => $totalSize,
                    'createdAt' => $token->created_at,
                    'expiresAt' => $token->expires_at,
                    'connectionCount' => $connectionCount,
                    'peerjsId' => $token->peerjs_id
                ];
            }
        }
        
        Flight::render('p2p-sender.latte', [
            'sessions' => $sessions,
            'sessionsJson' => json_encode($sessions)
        ]);
    }

    /**
     * Creates a new P2P share session.
     *
     * POST /api/p2p/create
     * Requires authentication.
     *
     * @return void
     */
    public function create(): void
    {
        // Require authentication
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::json(['error' => 'Authentication required'], 401);
            return;
        }

        $data = Flight::request()->data;
        $files = $data->files ?? [];

        if (empty($files) || !is_array($files)) {
            Flight::json(['error' => 'No files provided'], 400);
            return;
        }

        // Validate file metadata
        $validatedFiles = [];
        foreach ($files as $file) {
            if (!isset($file['name']) || !isset($file['size']) || !isset($file['type'])) {
                Flight::json(['error' => 'Invalid file metadata: missing required fields'], 400);
                return;
            }

            $validatedFiles[] = [
                'name' => $file['name'],
                'size' => (int) $file['size'],
                'type' => $file['type']
            ];
        }

        // Create the P2P share token
        $filesMetadata = json_encode($validatedFiles);
        $p2pToken = P2PShareToken::create($user->id, $filesMetadata);

        if (!$p2pToken->save()) {
            Flight::json(['error' => 'Failed to create P2P share'], 500);
            return;
        }

        // Return the share details
        Flight::json([
            'token' => $p2pToken->token,
            'shortcode' => $p2pToken->shortcode,
            'pin' => $p2pToken->pin,
            'expiresAt' => $p2pToken->expires_at,
            'files' => $validatedFiles
        ]);
    }

    /**
     * Gets share metadata (public endpoint).
     *
     * GET /api/p2p/@token
     *
     * @param string $token The share token.
     * @return void
     */
    public function show(string $token): void
    {
        $p2pToken = P2PShareToken::findByToken($token);

        if (!$p2pToken) {
            Flight::json(['error' => 'Share not found'], 404);
            return;
        }

        // Check if expired
        if ($p2pToken->isExpired()) {
            Flight::json(['error' => 'Share has expired'], 410);
            return;
        }

        // Return metadata only (not file contents)
        Flight::json([
            'token' => $p2pToken->token,
            'shortcode' => $p2pToken->shortcode,
            'expiresAt' => $p2pToken->expires_at,
            'files' => $p2pToken->getFilesMetadata(),
            'totalSize' => $p2pToken->getTotalSize(),
            'createdAt' => $p2pToken->created_at,
            'isExpired' => $p2pToken->isExpired(),
            'isLocked' => $p2pToken->isLocked(),
            'peerjsId' => $p2pToken->peerjs_id ?? ''
        ]);
    }

    /**
     * Renders the sender's P2P share page.
     *
     * GET /p2p/sender/@shortcode
     *
     * @param string $shortcode The share shortcode.
     * @return void
     */
    public function showSenderPage(string $shortcode): void
    {
        $p2pToken = P2PShareToken::findByShortcode($shortcode);

        if (!$p2pToken) {
            Flight::halt(404, 'Share not found');
            return;
        }

        // Check if expired
        if ($p2pToken->isExpired()) {
            Flight::halt(410, 'Share has expired');
            return;
        }

        // Get file metadata for display
        $files = $p2pToken->getFilesMetadata();
        $totalSize = $p2pToken->getTotalSize();

        // Get base URL
        $baseUrl = Flight::request()->base;

        Flight::render('p2p-sender.latte', [
            'token' => $p2pToken->token,
            'shortcode' => $p2pToken->shortcode,
            'pin' => $p2pToken->pin,
            'files' => $files,
            'totalSize' => $totalSize,
            'expiresAt' => $p2pToken->expires_at,
            'shareUrl' => $baseUrl . '/p2p/' . $p2pToken->shortcode,
            'peerjsId' => $p2pToken->peerjs_id
        ]);
    }

    /**
     * Renders the recipient download page.
     *
     * GET /p2p/@shortcode
     *
     * @param string $shortcode The share shortcode.
     * @return void
     */
    public function showPage(string $shortcode): void
    {
        $p2pToken = P2PShareToken::findByShortcode($shortcode);

        if (!$p2pToken) {
            Flight::halt(404, 'Share not found');
            return;
        }

        // Check if expired
        if ($p2pToken->isExpired()) {
            Flight::halt(410, 'Share has expired');
            return;
        }

        // Check if PIN was verified via cookie
        $cookieName = 'p2p_verified_' . substr($p2pToken->token, 0, 16);
        $pinVerified = isset($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] === '1';
        
        // Get file metadata for display
        $files = $p2pToken->getFilesMetadata();
        
        // Ensure files is always an array
        if (!is_array($files)) {
            $files = [];
        }
        
        $totalSize = $p2pToken->getTotalSize();

        // Format file sizes for display
        foreach ($files as &$file) {
            $file['sizeFormatted'] = $this->formatBytes($file['size'] ?? 0);
        }
        
        // Ensure all values are strings or primitives
        $token = is_string($p2pToken->token) ? $p2pToken->token : '';
        $peerjsId = is_string($p2pToken->peerjs_id) ? $p2pToken->peerjs_id : '';

        Flight::render('p2p-receive.latte', [
            'token' => $token,
            'shortcode' => is_string($p2pToken->shortcode) ? $p2pToken->shortcode : '',
            'pinVerified' => $pinVerified,
            'peerjsId' => $peerjsId,
            'files' => $files,
            'filesJson' => json_encode($files),
            'totalSize' => $totalSize,
            'totalSizeFormatted' => $this->formatBytes($totalSize),
            'expiresAt' => is_string($p2pToken->expires_at) ? $p2pToken->expires_at : '',
            'isLocked' => $p2pToken->isLocked(),
            'createdAt' => is_string($p2pToken->created_at) ? $p2pToken->created_at : ''
        ]);
    }

    /**
     * Cancels/expires a share (owner only).
     *
     * DELETE /api/p2p/@token
     * Requires authentication.
     *
     * @param string $token The share token.
     * @return void
     */
    public function delete(string $token): void
    {
        // Require authentication
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::json(['error' => 'Authentication required'], 401);
            return;
        }

        $p2pToken = P2PShareToken::findByToken($token);

        if (!$p2pToken) {
            Flight::json(['error' => 'Share not found'], 404);
            return;
        }

        // Check ownership
        if ($p2pToken->user_id !== $user->id) {
            Flight::json(['error' => 'Forbidden: not the owner of this share'], 403);
            return;
        }

        // Soft delete (set is_active = 0)
        if ($p2pToken->delete()) {
            Flight::json(['success' => true, 'message' => 'Share cancelled']);
        } else {
            Flight::json(['error' => 'Failed to cancel share'], 500);
        }
    }

    /**
     * Verifies the recipient's PIN.
     *
     * POST /api/p2p/@token/verify-pin
     * Public endpoint.
     *
     * @param string $token The share token.
     * @return void
     */
    public function verifyPin(string $token): void
    {
        $p2pToken = P2PShareToken::findByToken($token);

        if (!$p2pToken) {
            Flight::json(['error' => 'Share not found'], 404);
            return;
        }

        // Check if expired
        if ($p2pToken->isExpired()) {
            Flight::json(['error' => 'Share has expired'], 410);
            return;
        }

        // Check if locked
        if ($p2pToken->isLocked()) {
            Flight::json([
                'valid' => false,
                'locked' => true,
                'attemptsRemaining' => 0,
                'lockExpiresAt' => $p2pToken->pin_locked_at
            ]);
            return;
        }

        $data = Flight::request()->data;
        $pin = $data->pin ?? '';

        // Validate PIN format
        if (!preg_match('/^\d{2}$/', $pin)) {
            Flight::json(['error' => 'Invalid PIN format'], 400);
            return;
        }

        // Verify PIN
        $isValid = $p2pToken->verifyPin($pin);

        if ($isValid) {
            $attemptsRemaining = self::MAX_PIN_ATTEMPTS;
            // Set cookie to remember PIN was verified (expires in 1 hour)
            $cookieName = 'p2p_verified_' . substr($p2pToken->token, 0, 16);
            setcookie($cookieName, '1', time() + 3600, '/');
        } else {
            // Reload to get updated attempt count
            $p2pToken->load($p2pToken->id);
            $attemptsRemaining = max(0, self::MAX_PIN_ATTEMPTS - $p2pToken->pin_attempts);
        }

        Flight::json([
            'valid' => $isValid,
            'locked' => $p2pToken->isLocked(),
            'attemptsRemaining' => $attemptsRemaining
        ]);
    }

    /**
     * Registers a PeerJS connection for P2P signaling.
     *
     * POST /api/p2p/@token/connect
     * Public endpoint.
     *
     * @param string $token The share token.
     * @return void
     */
    public function connect(string $token): void
    {
        $p2pToken = P2PShareToken::findByToken($token);

        if (!$p2pToken) {
            Flight::json(['error' => 'Share not found'], 404);
            return;
        }

        // Check if expired
        if ($p2pToken->isExpired()) {
            Flight::json(['error' => 'Share has expired'], 410);
            return;
        }

        $data = Flight::request()->data;
        $peerjsId = $data->peerjs_id ?? '';

        if (empty($peerjsId)) {
            Flight::json(['error' => 'PeerJS ID is required'], 400);
            return;
        }

        // Store PeerJS ID
        $p2pToken->peerjs_id = $peerjsId;

        if ($p2pToken->save()) {
            Flight::json(['success' => true]);
        } else {
            Flight::json(['error' => 'Failed to register connection'], 500);
        }
    }

    /**
     * Lists all active P2P sessions for the current user.
     *
     * GET /api/p2p
     * Requires authentication.
     *
     * @return void
     */
    public function listSessions(): void
    {
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::json(['error' => 'Authentication required'], 401);
            return;
        }

        $sessions = P2PShareToken::findActiveByUserId($user->id);
        
        $result = [];
        foreach ($sessions as $session) {
            $files = $session->getFilesMetadata();
            $connectionCount = P2PConnection::countActiveByTokenId($session->id);
            
            $result[] = [
                'token' => $session->token,
                'shortcode' => $session->shortcode,
                'pin' => $session->pin,
                'files' => $files,
                'fileCount' => count($files),
                'totalSize' => $session->getTotalSize(),
                'createdAt' => $session->created_at,
                'expiresAt' => $session->expires_at,
                'connectionCount' => $connectionCount,
                'peerjsId' => $session->peerjs_id
            ];
        }
        
        Flight::json(['sessions' => $result]);
    }

    /**
     * Lists all connections for a P2P session.
     *
     * GET /api/p2p/@token/connections
     * Requires authentication (owner only).
     *
     * @param string $token The share token.
     * @return void
     */
    public function listConnections(string $token): void
    {
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::json(['error' => 'Authentication required'], 401);
            return;
        }

        $p2pToken = P2PShareToken::findByToken($token);
        
        if (!$p2pToken) {
            Flight::json(['error' => 'Share not found'], 404);
            return;
        }
        
        // Check ownership
        if ($p2pToken->user_id !== $user->id) {
            Flight::json(['error' => 'Forbidden: not the owner of this share'], 403);
            return;
        }

        $connections = P2PConnection::findByTokenId($p2pToken->id);
        
        $result = [];
        foreach ($connections as $conn) {
            $result[] = [
                'id' => $conn->id,
                'recipientPeerjsId' => $conn->recipient_peerjs_id,
                'recipientIp' => $conn->recipient_ip,
                'status' => $conn->status,
                'connectedAt' => $conn->connected_at,
                'disconnectedAt' => $conn->disconnected_at
            ];
        }
        
        Flight::json(['connections' => $result]);
    }

    /**
     * Creates a new connection record for a P2P session.
     *
     * POST /api/p2p/@token/connections
     * Public endpoint.
     *
     * @param string $token The share token.
     * @return void
     */
    public function createConnection(string $token): void
    {
        $p2pToken = P2PShareToken::findByToken($token);
        
        if (!$p2pToken) {
            Flight::json(['error' => 'Share not found'], 404);
            return;
        }
        
        // Check if expired
        if ($p2pToken->isExpired()) {
            Flight::json(['error' => 'Share has expired'], 410);
            return;
        }

        $data = Flight::request()->data;
        $peerjsId = $data->recipient_peerjs_id ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        // Create or reactivate connection
        $connection = P2PConnection::create($p2pToken->id, $peerjsId, $ipAddress);
        
        if ($connection) {
            Flight::json([
                'success' => true,
                'connectionId' => $connection->id
            ]);
        } else {
            Flight::json(['error' => 'Failed to create connection'], 500);
        }
    }

    /**
     * Marks a connection as disconnected.
     *
     * DELETE /api/p2p/@token/connections/@id
     * Public endpoint.
     *
     * @param string $token The share token.
     * @param int $id The connection ID.
     * @return void
     */
    public function deleteConnection(string $token, int $id): void
    {
        $p2pToken = P2PShareToken::findByToken($token);
        
        if (!$p2pToken) {
            Flight::json(['error' => 'Share not found'], 404);
            return;
        }

        $connection = P2PConnection::findById($id);
        
        if (!$connection) {
            Flight::json(['error' => 'Connection not found'], 404);
            return;
        }
        
        // Verify the connection belongs to this token
        if ($connection->p2p_token_id !== $p2pToken->id) {
            Flight::json(['error' => 'Connection does not belong to this share'], 403);
            return;
        }

        if ($connection->disconnect()) {
            Flight::json(['success' => true]);
        } else {
            Flight::json(['error' => 'Failed to disconnect'], 500);
        }
    }

    /**
     * Formats bytes into human-readable size.
     *
     * @param int $bytes The number of bytes.
     * @param int $precision The decimal precision.
     * @return string The formatted size string.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
