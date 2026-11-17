<?php

/**
 * P2PRelayController.php
 *
 * HTTP endpoints of the PHP relay that carries P2P transfers when WebRTC cannot connect.
 * The relay only ever sees encrypted batches; the key stays in the share link's fragment.
 *
 * Two roles take part in a session:
 *  - host: the logged-in owner of the share or receive session (listens for channels)
 *  - guest: the visitor who verified the PIN (opens a channel)
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.9.0
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\P2PReceiveSession;
use Phuppi\P2PShareToken;
use Phuppi\Service\P2PRelay;

class P2PRelayController
{
    /**
     * Server-session key recording that a visitor verified a share's PIN.
     */
    public static function sharePinSessionKey(P2PShareToken $share): string
    {
        return 'p2p_share_verified_' . $share->id;
    }

    /**
     * Reports whether the relay is available and how large a batch may be.
     *
     * GET /api/p2p/relay/capabilities
     */
    public function capabilities(): void
    {
        Flight::json([
            'enabled' => P2PRelay::isEnabled(),
            'maxBatchBytes' => P2PRelay::maxBatchBytes(),
            'maxBytes' => P2PRelay::maxBytes(),
        ]);
    }

    /**
     * Opens a channel (guest only).
     *
     * POST /api/p2p/relay/@type/@token/channels
     */
    public function createChannel(string $type, string $token): void
    {
        if (!$this->authorize($type, $token, 'guest')) {
            return;
        }

        P2PRelay::touchSession($type, $token);
        $id = P2PRelay::createChannel($type, $token);
        if ($id === null) {
            Flight::json(['error' => 'Too many open relay channels'], 429);
            return;
        }

        Flight::json(['channel' => $id]);
    }

    /**
     * Lists channels opened after a row ID (host only).
     *
     * GET /api/p2p/relay/@type/@token/channels?after=<rid>
     */
    public function listChannels(string $type, string $token): void
    {
        if (!$this->authorize($type, $token, 'host')) {
            return;
        }

        // The owner's open page counts as activity
        P2PRelay::touchSession($type, $token);
        $after = (int) (Flight::request()->query['after'] ?? 0);
        Flight::json(['channels' => P2PRelay::listChannels($type, $token, $after)]);
    }

    /**
     * Returns the session's drop (host creates it on demand, guest only looks).
     *
     * POST|GET /api/p2p/relay/@type/@token/drop?role=<host|guest>
     */
    public function drop(string $type, string $token): void
    {
        $this->guard(fn () => $this->doDrop($type, $token));
    }

    private function doDrop(string $type, string $token): void
    {
        $role = $this->roleFromRequest();
        if (!$this->authorize($type, $token, $role)) {
            return;
        }

        P2PRelay::touchSession($type, $token);
        $create = Flight::request()->method === 'POST' && $role === 'host';
        $drop = P2PRelay::findOrCreateDrop($type, $token, $create);

        Flight::json([
            'exists' => $drop !== null,
            'channel' => $drop['id'] ?? null,
            'last' => $drop ? (int) $drop['h2g_last'] : 0,
            'bytes' => $drop ? (int) $drop['bytes_stored'] : 0,
            'idleSeconds' => P2PRelay::SESSION_IDLE_TTL,
        ]);
    }

    /**
     * Deletes the session's drop and everything stored in it (host only).
     *
     * DELETE /api/p2p/relay/@type/@token/drop?role=host
     */
    public function deleteDrop(string $type, string $token): void
    {
        $this->guard(function () use ($type, $token) {
            if (!$this->authorize($type, $token, 'host')) {
                return;
            }
            $drop = P2PRelay::findOrCreateDrop($type, $token, false);
            if ($drop) {
                P2PRelay::close($drop['id']);
            }
            $this->emptyResponse(204);
        });
    }

    /**
     * Stores the next batch the caller is writing.
     *
     * POST /api/p2p/relay/@type/@token/@channel/data?role=<host|guest>&seq=<n>
     */
    public function send(string $type, string $token, string $channel): void
    {
        $this->guard(fn () => $this->doSend($type, $token, $channel));
    }

    private function doSend(string $type, string $token, string $channel): void
    {
        $role = $this->roleFromRequest();
        if (!$this->authorize($type, $token, $role) || !$this->channelOr404($channel, $type, $token)) {
            return;
        }

        if (!$this->dropAllows($channel, $type, $token, $role, true)) {
            return;
        }

        $seq = (int) (Flight::request()->query['seq'] ?? 0);
        if ($seq < 1) {
            $this->emptyResponse(400);
            return;
        }

        $body = file_get_contents('php://input');
        $result = P2PRelay::append($channel, $this->writeDirection($role), $seq, $body === false ? '' : $body);

        switch ($result) {
            case P2PRelay::RESULT_OK:
                // Stored data is activity. Idle polling is not, so an abandoned tab cannot keep a drop alive
                P2PRelay::touchSession($type, $token);
                $this->emptyResponse(204);
                break;
            case P2PRelay::RESULT_FULL:
                $this->emptyResponse(429, ['Retry-After' => '1']);
                break;
            case P2PRelay::RESULT_ORDER:
                $this->emptyResponse(409);
                break;
            case P2PRelay::RESULT_CAP:
            case P2PRelay::RESULT_TOO_LARGE:
                $this->emptyResponse(413, ['X-Relay-Reason' => $result]);
                break;
            case P2PRelay::RESULT_CLOSED:
                $this->emptyResponse(410, ['X-Relay-State' => 'closed']);
                break;
            default:
                $this->emptyResponse(404);
        }
    }

    /**
     * Returns the next batch the caller reads, acknowledging everything up to ?after.
     *
     * GET /api/p2p/relay/@type/@token/@channel/data?role=<host|guest>&after=<n>
     */
    public function receive(string $type, string $token, string $channel): void
    {
        $this->guard(fn () => $this->doReceive($type, $token, $channel));
    }

    private function doReceive(string $type, string $token, string $channel): void
    {
        $role = $this->roleFromRequest();
        if (!$this->authorize($type, $token, $role) || !$this->channelOr404($channel, $type, $token)) {
            return;
        }

        $after = (int) (Flight::request()->query['after'] ?? 0);
        $result = P2PRelay::fetch($channel, $this->readDirection($role), $after);

        if ($result['state'] === 'data') {
            P2PRelay::touchSession($type, $token);
            $this->emptyResponse(200, [
                'Content-Type' => 'application/octet-stream',
                'X-Relay-State' => 'open',
                'X-Relay-Seq' => (string) $result['seq'],
            ], $result['body']);
            return;
        }

        $this->emptyResponse($result['state'] === P2PRelay::RESULT_MISSING ? 404 : 204, [
            'X-Relay-State' => $result['state'] === P2PRelay::RESULT_CLOSED ? 'closed' : 'open',
        ]);
    }

    /**
     * Closes a channel.
     *
     * DELETE /api/p2p/relay/@type/@token/@channel?role=<host|guest>
     */
    public function close(string $type, string $token, string $channel): void
    {
        $this->guard(fn () => $this->doClose($type, $token, $channel));
    }

    private function doClose(string $type, string $token, string $channel): void
    {
        $role = $this->roleFromRequest();
        if (!$this->authorize($type, $token, $role) || !$this->channelOr404($channel, $type, $token)) {
            return;
        }

        if (!$this->dropAllows($channel, $type, $token, $role, true)) {
            return;
        }

        P2PRelay::close($channel);
        $this->emptyResponse(204);
    }

    /**
     * Checks that the session exists, is not expired, and the caller holds the claimed role.
     * Writes the error response itself and returns false when the check fails.
     */
    private function authorize(string $type, string $token, string $role): bool
    {
        if (!P2PRelay::isEnabled()) {
            Flight::json(['error' => 'The relay is disabled'], 403);
            return false;
        }

        if ($type === 'share') {
            $session = P2PShareToken::findByToken($token);
            $verifiedKey = $session ? self::sharePinSessionKey($session) : null;
        } elseif ($type === 'receive') {
            $session = P2PReceiveSession::findByToken($token);
            $verifiedKey = $session ? 'p2p_receive_verified_' . $session->id : null;
        } else {
            Flight::json(['error' => 'Unknown session type'], 404);
            return false;
        }

        if (!$session) {
            Flight::json(['error' => 'Session not found'], 404);
            return false;
        }
        if ($session->isExpired()) {
            Flight::json(['error' => 'Session has expired'], 410);
            return false;
        }

        if ($role === 'host') {
            $user = Flight::user();
            $allowed = $user && $user->id && (int) $session->user_id === (int) $user->id;
        } else {
            $allowed = Flight::session()->get($verifiedKey) === true;
        }

        if (!$allowed) {
            Flight::json(['error' => 'Not authorized for this relay session'], 403);
            return false;
        }

        return true;
    }

    /**
     * Runs an action and logs anything it throws, so a failing relay request leaves a trace
     * (Flight's default 500 page is not logged).
     */
    private function guard(callable $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            Flight::logger()->error('P2P relay: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            throw $e;
        }
    }

    /**
     * A drop is written and deleted by the host only. Ordinary channels are unrestricted.
     * Writes the error response itself and returns false when the caller may not do it.
     */
    private function dropAllows(string $channel, string $type, string $token, string $role, bool $hostOnly): bool
    {
        $row = P2PRelay::findChannel($channel, $type, $token);
        if ($row && (int) $row['is_drop'] === 1 && $hostOnly && $role !== 'host') {
            $this->emptyResponse(403);
            return false;
        }
        return true;
    }

    private function channelOr404(string $channel, string $type, string $token): bool
    {
        if (P2PRelay::findChannel($channel, $type, $token) === null) {
            $this->emptyResponse(404);
            return false;
        }
        return true;
    }

    private function roleFromRequest(): string
    {
        return (Flight::request()->query['role'] ?? '') === 'host' ? 'host' : 'guest';
    }

    private function writeDirection(string $role): string
    {
        return $role === 'host' ? P2PRelay::DIR_H2G : P2PRelay::DIR_G2H;
    }

    private function readDirection(string $role): string
    {
        return $role === 'host' ? P2PRelay::DIR_G2H : P2PRelay::DIR_H2G;
    }

    /**
     * Sends a response with optional headers and a raw body, then stops the request.
     *
     * @param array<string, string> $headers
     */
    private function emptyResponse(int $status, array $headers = [], string $body = ''): void
    {
        $response = Flight::response();
        $response->status($status);
        $response->header('Cache-Control', 'no-store');
        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }
        Flight::halt($status, $body);
    }
}
