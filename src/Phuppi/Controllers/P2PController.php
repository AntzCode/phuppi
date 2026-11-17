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
use Phuppi\P2PReceiveSession;
use Phuppi\P2PShareToken;
use Phuppi\Service\P2PRelay;

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
     * POST /api/p2p/share/create
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
     * GET /api/p2p/share/@token
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
     * GET /p2p/share/sender/@shortcode
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

        P2PRelay::touchSession('share', $p2pToken->token);

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
            'shareUrl' => $baseUrl . '/p2p/share/' . $p2pToken->shortcode,
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

        P2PRelay::touchSession('share', $p2pToken->token);

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
     * DELETE /api/p2p/share/@token
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
     * POST /api/p2p/share/@token/verify-pin
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

        P2PRelay::touchSession('share', $p2pToken->token);

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
            // The relay trusts this server-side flag, not the cookie (a client can forge cookies)
            Flight::session()->set(P2PRelayController::sharePinSessionKey($p2pToken), true);
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
     * POST /api/p2p/share/@token/connect
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

        P2PRelay::touchSession('share', $p2pToken->token);

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
     * GET /api/p2p/share
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
     * GET /api/p2p/share/@token/connections
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
     * POST /api/p2p/share/@token/connections
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

        P2PRelay::touchSession('share', $p2pToken->token);

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
     * DELETE /api/p2p/share/@token/connections/@id
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

    // ==================== Reverse P2P Receive Session Methods ====================

    /**
     * Renders the P2P receive index page (list of receive sessions).
     * 
     * GET /p2p/receive
     * Requires authentication.
     * 
     * This now auto-creates a session and redirects to the create page
     * to provide a more direct flow for users.
     *
     * @return void
     */
    public function receiveIndex(): void
    {
        // Require authentication
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::redirect('/login');
            return;
        }

        // Auto-create a session and redirect to the create page
        // This provides a more direct flow - user immediately sees QR/PIN/URL to share
        $session = P2PReceiveSession::create($user->id);

        if (!$session->save()) {
            // If creation fails, show the index page with error
            $sessions = [];
            Flight::render('p2p-receive-index.latte', [
                'sessions' => $sessions,
                'sessionsJson' => json_encode($sessions),
                'error' => 'Failed to create receive session'
            ]);
            return;
        }

        // Redirect to the create page showing QR/PIN/URL
        Flight::redirect('/p2p/receive/create/' . $session->token);
    }

    /**
     * Renders the receive session create page.
     *
     * GET /p2p/receive/create/@token
     * Requires authentication.
     *
     * @param string $token The session token.
     * @return void
     */
    public function showReceiveCreatePage(string $token): void
    {
        // Require authentication
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::redirect('/login');
            return;
        }
        
        $session = P2PReceiveSession::findByToken($token);
        
        if (!$session) {
            Flight::halt(404, 'Session not found');
            return;
        }
        
        // Check ownership
        if ($session->user_id !== $user->id) {
            Flight::halt(403, 'Forbidden: not the owner of this session');
            return;
        }
        
        // Check if expired
        if ($session->isExpired()) {
            Flight::halt(410, 'Session has expired');
            return;
        }
        
        // Get full URL with scheme and host
        $request = Flight::request();
        $scheme = isset($request->scheme) ? $request->scheme : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host = isset($request->host) ? $request->host : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        // $request->base may or may not have a trailing slash, so normalize by rtrim
        $baseUrl = rtrim($scheme . '://' . $host . $request->base, '/');
        // Use trim to ensure single slash prefix, avoiding double slashes
        $shareUrl = $baseUrl . '/' . ltrim('p2p/receive/guest/' . $session->shortcode, '/');
        
        Flight::render('p2p/receive/create.latte', [
            'token' => $session->token,
            'shortcode' => $session->shortcode,
            'pin' => $session->pin,
            'shareUrl' => $shareUrl,
            'expiresAt' => $session->expires_at
        ]);
    }

    /**
     * Renders the receive session waiting page.
     *
     * GET /p2p/receive/waiting/@token
     * Requires authentication.
     *
     * @param string $token The session token.
     * @return void
     */
    public function showReceiveWaitingPage(string $token): void
    {
        // Require authentication
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::redirect('/login');
            return;
        }
        
        $session = P2PReceiveSession::findByToken($token);
        
        if (!$session) {
            Flight::halt(404, 'Session not found');
            return;
        }
        
        // Check ownership
        if ($session->user_id !== $user->id) {
            Flight::halt(403, 'Forbidden: not the owner of this session');
            return;
        }
        
        // Check if expired
        if ($session->isExpired()) {
            Flight::halt(410, 'Session has expired');
            return;
        }

        P2PRelay::touchSession('receive', $session->token);
        
        // Get full URL with scheme and host
        $request = Flight::request();
        $scheme = isset($request->scheme) ? $request->scheme : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host = isset($request->host) ? $request->host : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        // $request->base may or may not have a trailing slash, so normalize by rtrim
        $baseUrl = rtrim($scheme . '://' . $host . $request->base, '/');
        // Use trim to ensure single slash prefix, avoiding double slashes
        $shareUrl = $baseUrl . '/' . ltrim('p2p/receive/guest/' . $session->shortcode, '/');
        
        Flight::render('p2p/receive/waiting.latte', [
            'token' => $session->token,
            'shortcode' => $session->shortcode,
            'pin' => $session->pin,
            'shareUrl' => $shareUrl,
            'expiresAt' => $session->expires_at
        ]);
    }

    /**
     * Lists all active P2P receive sessions for the current user.
     *
     * GET /api/p2p/receive
     * Requires authentication.
     *
     * @return void
     */
    public function listReceiveSessions(): void
    {
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::json(['error' => 'Authentication required'], 401);
            return;
        }

        $sessions = P2PReceiveSession::findActiveByUserId($user->id);

        $result = [];
        foreach ($sessions as $session) {
            $files = $session->getFilesMetadata();

            $result[] = [
                'token' => $session->token,
                'shortcode' => $session->shortcode,
                'pin' => $session->pin,
                'files' => $files,
                'fileCount' => count($files),
                'totalSize' => $session->getTotalSize(),
                'createdAt' => $session->created_at,
                'expiresAt' => $session->expires_at,
                'peerjsId' => $session->peerjs_id,
                'isExpired' => $session->isExpired()
            ];
        }

        Flight::json(['sessions' => $result]);
    }

    /**
     * Creates a new P2P receive session.
     *
     * POST /api/p2p/receive/create
     * Requires authentication.
     *
     * @return void
     */
    public function createReceiveSession(): void
    {
        // Require authentication
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::json(['error' => 'Authentication required'], 401);
            return;
        }

        // Create the P2P receive session
        $session = P2PReceiveSession::create($user->id);

        if (!$session->save()) {
            Flight::json(['error' => 'Failed to create receive session'], 500);
            return;
        }

        // Return the session details
        Flight::json([
            'token' => $session->token,
            'shortcode' => $session->shortcode,
            'pin' => $session->pin,
            'expiresAt' => $session->expires_at
        ]);
    }

    /**
     * Gets receive session metadata (public endpoint).
     *
     * GET /api/p2p/receive/@token
     *
     * @param string $token The session token.
     * @return void
     */
    public function showReceiveSession(string $token): void
    {
        $session = P2PReceiveSession::findByToken($token);

        if (!$session) {
            Flight::json(['error' => 'Session not found'], 404);
            return;
        }

        // Check if expired
        if ($session->isExpired()) {
            Flight::json(['error' => 'Session has expired'], 410);
            return;
        }

        Flight::logger()->info('Fetching receive session', [
            'token' => $token,
            'peerjs_id' => $session->peerjs_id
        ]);

        $isOwner = $this->isReceiveSessionOwner($session);

        // Return metadata only (not file contents). The receiver's peer ID is only
        // revealed after PIN verification, and the file list only to the owner.
        Flight::json([
            'token' => $session->token,
            'shortcode' => $session->shortcode,
            'expiresAt' => $session->expires_at,
            'files' => $isOwner ? $session->getFilesMetadata() : [],
            'totalSize' => $isOwner ? $session->getTotalSize() : 0,
            'createdAt' => $session->created_at,
            'isExpired' => $session->isExpired(),
            'isLocked' => $session->isLocked(),
            'peerjsId' => ($isOwner || $this->isReceivePinVerified($session)) ? ($session->peerjs_id ?? '') : ''
        ]);
    }

    /**
     * Renders the guest sender page.
     *
     * GET /p2p/receive/@shortcode
     *
     * @param string $shortcode The session shortcode.
     * @return void
     */
    public function showReceivePage(string $shortcode): void
    {
        $session = P2PReceiveSession::findByShortcode($shortcode);

        if (!$session) {
            Flight::halt(404, 'Session not found');
            return;
        }

        // Check if expired
        if ($session->isExpired()) {
            Flight::halt(410, 'Session has expired');
            return;
        }

        P2PRelay::touchSession('receive', $session->token);

        $pinVerified = $this->isReceivePinVerified($session);

        // Get file metadata for display
        $files = $session->getFilesMetadata();

        // Ensure files is always an array
        if (!is_array($files)) {
            $files = [];
        }

        $totalSize = $session->getTotalSize();

        // Format file sizes for display
        foreach ($files as &$file) {
            $file['sizeFormatted'] = $this->formatBytes($file['size'] ?? 0);
        }

        // Ensure all values are strings or primitives
        $token = is_string($session->token) ? $session->token : '';
        $peerjsId = is_string($session->peerjs_id) ? $session->peerjs_id : '';

        Flight::render('p2p-receive.latte', [
            'token' => $token,
            'shortcode' => is_string($session->shortcode) ? $session->shortcode : '',
            'pinVerified' => $pinVerified,
            'peerjsId' => $peerjsId,
            'files' => $files,
            'filesJson' => json_encode($files),
            'totalSize' => $totalSize,
            'totalSizeFormatted' => $this->formatBytes($totalSize),
            'expiresAt' => is_string($session->expires_at) ? $session->expires_at : '',
            'isLocked' => $session->isLocked(),
            'createdAt' => is_string($session->created_at) ? $session->created_at : ''
        ]);
    }

    /**
     * Renders the guest sender send page (new sender interface).
     *
     * GET /p2p/receive/guest/@shortcode
     *
     * @param string $shortcode The session shortcode.
     * @return void
     */
    public function showReceiveSendPage(string $shortcode): void
    {
        $session = P2PReceiveSession::findByShortcode($shortcode);

        if (!$session) {
            Flight::halt(404, 'Session not found');
            return;
        }

        // Check if expired
        if ($session->isExpired()) {
            Flight::halt(410, 'Session has expired');
            return;
        }

        P2PRelay::touchSession('receive', $session->token);

        // Check if locked
        if ($session->isLocked()) {
            Flight::halt(403, 'Too many failed PIN attempts');
            return;
        }

        // Get the receiver's name (owner of the session)
        $receiverName = 'User'; // Default fallback
        $owner = $session->getUser();
        if ($owner) {
            $receiverName = is_string($owner->username) ? $owner->username : 'User';
        }

        // Ensure all values are strings or primitives
        $token = is_string($session->token) ? $session->token : '';

        Flight::render('p2p/receive/send.latte', [
            'token' => $token,
            'shortcode' => is_string($session->shortcode) ? $session->shortcode : '',
            'receiverName' => $receiverName,
            'expiresAt' => is_string($session->expires_at) ? $session->expires_at : ''
        ]);
    }

    /**
     * Cancels/expires a receive session (owner only).
     *
     * DELETE /api/p2p/receive/@token
     * Requires authentication.
     *
     * @param string $token The session token.
     * @return void
     */
    public function deleteReceiveSession(string $token): void
    {
        // Require authentication
        $user = Flight::user();
        if (!$user || !$user->id) {
            Flight::json(['error' => 'Authentication required'], 401);
            return;
        }

        $session = P2PReceiveSession::findByToken($token);

        if (!$session) {
            Flight::json(['error' => 'Session not found'], 404);
            return;
        }

        // Check ownership
        if ($session->user_id !== $user->id) {
            Flight::json(['error' => 'Forbidden: not the owner of this session'], 403);
            return;
        }

        // Soft delete (set is_active = 0)
        if ($session->delete()) {
            Flight::json(['success' => true, 'message' => 'Session cancelled']);
        } else {
            Flight::json(['error' => 'Failed to cancel session'], 500);
        }
    }

    /**
     * Verifies the sender's PIN.
     *
     * POST /api/p2p/receive/@token/verify-pin
     * Public endpoint.
     *
     * @param string $token The session token.
     * @return void
     */
    public function verifyReceivePin(string $token): void
    {
        $session = P2PReceiveSession::findByToken($token);

        if (!$session) {
            Flight::json(['error' => 'Session not found'], 404);
            return;
        }

        // Check if expired
        if ($session->isExpired()) {
            Flight::json(['error' => 'Session has expired'], 410);
            return;
        }

        P2PRelay::touchSession('receive', $session->token);

        // Check if locked
        if ($session->isLocked()) {
            Flight::json([
                'valid' => false,
                'locked' => true,
                'attemptsRemaining' => 0,
                'lockExpiresAt' => $session->pin_locked_at
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
        $isValid = $session->verifyPin($pin);

        if ($isValid) {
            $attemptsRemaining = self::MAX_PIN_ATTEMPTS;
            // Remember PIN verification server-side (a client cookie could be forged)
            Flight::session()->set($this->receivePinSessionKey($session), true);
        } else {
            // Reload to get updated attempt count
            $session->load($session->id);
            $attemptsRemaining = max(0, self::MAX_PIN_ATTEMPTS - $session->pin_attempts);
        }

        Flight::json([
            'valid' => $isValid,
            'locked' => $session->isLocked(),
            'attemptsRemaining' => $attemptsRemaining
        ]);
    }

    /**
     * Registers a PeerJS connection for P2P signaling.
     *
     * POST /api/p2p/receive/@token/connect
     * Requires authentication (session owner only).
     *
     * @param string $token The session token.
     * @return void
     */
    public function connectReceiveSession(string $token): void
    {
        $session = P2PReceiveSession::findByToken($token);

        if (!$session) {
            Flight::json(['error' => 'Session not found'], 404);
            return;
        }

        // Check if expired
        if ($session->isExpired()) {
            Flight::json(['error' => 'Session has expired'], 410);
            return;
        }

        P2PRelay::touchSession('receive', $session->token);

        // Only the receiving owner may change the session
        if (!$this->isReceiveSessionOwner($session)) {
            Flight::json(['error' => 'Forbidden: not the owner of this session'], 403);
            return;
        }

        $data = Flight::request()->data;
        $peerjsId = $data->peerjs_id ?? '';

        if (empty($peerjsId)) {
            Flight::json(['error' => 'PeerJS ID is required'], 400);
            return;
        }

        // Store PeerJS ID
        $session->peerjs_id = $peerjsId;

        Flight::logger()->info('Registering PeerJS ID for receive session', [
            'token' => $token,
            'peerjs_id' => $peerjsId
        ]);

        if ($session->save()) {
            Flight::logger()->info('Successfully registered PeerJS ID');
            Flight::json(['success' => true]);
        } else {
            Flight::logger()->error('Failed to save PeerJS ID', ['token' => $token]);
            Flight::json(['error' => 'Failed to register connection'], 500);
        }
    }

    /**
     * Saves received files metadata to the session.
     *
     * POST /api/p2p/receive/@token/complete
     * Requires authentication (session owner only).
     *
     * @param string $token The session token.
     * @return void
     */
    public function completeReceiveSession(string $token): void
    {
        $session = P2PReceiveSession::findByToken($token);

        if (!$session) {
            Flight::json(['error' => 'Session not found'], 404);
            return;
        }

        // Check if expired
        if ($session->isExpired()) {
            Flight::json(['error' => 'Session has expired'], 410);
            return;
        }

        // Only the receiving owner may change the session
        if (!$this->isReceiveSessionOwner($session)) {
            Flight::json(['error' => 'Forbidden: not the owner of this session'], 403);
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

        // Save files metadata to session
        $session->setFilesMetadata($validatedFiles);

        if ($session->save()) {
            Flight::json([
                'success' => true,
                'files' => $validatedFiles
            ]);
        } else {
            Flight::json(['error' => 'Failed to save files'], 500);
        }
    }

    /**
     * Checks whether the current user owns the receive session.
     *
     * @param P2PReceiveSession $session The receive session.
     * @return bool True if the logged-in user is the owner.
     */
    private function isReceiveSessionOwner(P2PReceiveSession $session): bool
    {
        $user = Flight::user();

        return $user && $user->id && (int) $session->user_id === (int) $user->id;
    }

    /**
     * Checks whether the current visitor has verified the session PIN.
     *
     * @param P2PReceiveSession $session The receive session.
     * @return bool True if the PIN was verified in this server session.
     */
    private function isReceivePinVerified(P2PReceiveSession $session): bool
    {
        return Flight::session()->get($this->receivePinSessionKey($session)) === true;
    }

    /**
     * Returns the server-session key that records PIN verification.
     *
     * @param P2PReceiveSession $session The receive session.
     * @return string The session key.
     */
    private function receivePinSessionKey(P2PReceiveSession $session): string
    {
        return 'p2p_receive_verified_' . $session->id;
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
