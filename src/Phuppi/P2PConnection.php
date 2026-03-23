<?php

namespace Phuppi;

/**
 * P2P Connection Model
 * 
 * Tracks individual recipient connections to P2P share sessions.
 * Enables multiple recipients per session and connection status tracking.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 */

use Flight;
use PDO;

class P2PConnection
{
    /** @var int|null Primary key */
    public $id;

    /** @var int P2P share token ID */
    public $p2p_token_id;

    /** @var string|null PeerJS ID of the recipient */
    public $recipient_peerjs_id;

    /** @var string|null IP address of the recipient */
    public $recipient_ip;

    /** @var string Connection status: connected, disconnected, completed */
    public $status;

    /** @var string|null Connection timestamp */
    public $connected_at;

    /** @var string|null Disconnection timestamp */
    public $disconnected_at;

    /**
     * Constructor for P2PConnection.
     *
     * @param array $data Initial data to populate the object.
     */
    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->p2p_token_id = $data['p2p_token_id'] ?? 0;
        $this->recipient_peerjs_id = $data['recipient_peerjs_id'] ?? null;
        $this->recipient_ip = $data['recipient_ip'] ?? null;
        $this->status = $data['status'] ?? 'connected';
        $this->connected_at = $data['connected_at'] ?? date('Y-m-d H:i:s');
        $this->disconnected_at = $data['disconnected_at'] ?? null;
    }

    /**
     * Find a connection by ID.
     *
     * @param int $id Connection ID.
     * @return P2PConnection|null The connection or null if not found.
     */
    public static function findById(int $id): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_connections WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        return new self($row);
    }

    /**
     * Find all connections for a P2P token.
     *
     * @param int $tokenId P2P token ID.
     * @return array Array of P2PConnection objects.
     */
    public static function findByTokenId(int $tokenId): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_connections WHERE p2p_token_id = ? ORDER BY connected_at DESC');
        $stmt->execute([$tokenId]);
        
        $connections = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $connections[] = new self($row);
        }
        
        return $connections;
    }

    /**
     * Find all active (connected) connections for a P2P token.
     *
     * @param int $tokenId P2P token ID.
     * @return array Array of P2PConnection objects.
     */
    public static function findActiveByTokenId(int $tokenId): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_connections WHERE p2p_token_id = ? AND status = ? ORDER BY connected_at DESC');
        $stmt->execute([$tokenId, 'connected']);
        
        $connections = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $connections[] = new self($row);
        }
        
        return $connections;
    }

    /**
     * Find connection by recipient PeerJS ID.
     *
     * @param string $peerjsId Recipient PeerJS ID.
     * @return P2PConnection|null The connection or null if not found.
     */
    public static function findByPeerjsId(string $peerjsId): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_connections WHERE recipient_peerjs_id = ? AND status = ? LIMIT 1');
        $stmt->execute([$peerjsId, 'connected']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        return new self($row);
    }

    /**
     * Create a new connection record.
     *
     * @param int $tokenId P2P token ID.
     * @param string|null $peerjsId Recipient PeerJS ID.
     * @param string|null $ipAddress Recipient IP address.
     * @return P2PConnection|null The created connection or null on failure.
     */
    public static function create(int $tokenId, ?string $peerjsId = null, ?string $ipAddress = null): ?self
    {
        $db = Flight::db();
        
        // Check if connection already exists for this recipient
        if ($peerjsId) {
            $existing = self::findByPeerjsId($peerjsId);
            if ($existing) {
                // Reactivate existing connection
                $existing->status = 'connected';
                $existing->disconnected_at = null;
                $existing->connected_at = date('Y-m-d H:i:s');
                $existing->save();
                return $existing;
            }
        }
        
        $stmt = $db->prepare('
            INSERT INTO p2p_connections (p2p_token_id, recipient_peerjs_id, recipient_ip, status, connected_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        
        $now = date('Y-m-d H:i:s');
        $result = $stmt->execute([
            $tokenId,
            $peerjsId,
            $ipAddress,
            'connected',
            $now
        ]);
        
        if ($result) {
            $connection = new self([
                'p2p_token_id' => $tokenId,
                'recipient_peerjs_id' => $peerjsId,
                'recipient_ip' => $ipAddress,
                'status' => 'connected',
                'connected_at' => $now
            ]);
            $connection->id = (int) $db->lastInsertId();
            return $connection;
        }
        
        return null;
    }

    /**
     * Save the connection to the database.
     *
     * @return bool True on success, false on failure.
     */
    public function save(): bool
    {
        $db = Flight::db();
        
        if ($this->id) {
            // Update existing
            $stmt = $db->prepare('
                UPDATE p2p_connections 
                SET recipient_peerjs_id = ?, recipient_ip = ?, status = ?, disconnected_at = ?
                WHERE id = ?
            ');
            return $stmt->execute([
                $this->recipient_peerjs_id,
                $this->recipient_ip,
                $this->status,
                $this->disconnected_at,
                $this->id
            ]);
        } else {
            // Insert new
            $stmt = $db->prepare('
                INSERT INTO p2p_connections (p2p_token_id, recipient_peerjs_id, recipient_ip, status, connected_at, disconnected_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $result = $stmt->execute([
                $this->p2p_token_id,
                $this->recipient_peerjs_id,
                $this->recipient_ip,
                $this->status,
                $this->connected_at,
                $this->disconnected_at
            ]);
            
            if ($result) {
                $this->id = (int) $db->lastInsertId();
            }
            
            return $result;
        }
    }

    /**
     * Mark the connection as disconnected.
     *
     * @return bool True on success, false on failure.
     */
    public function disconnect(): bool
    {
        $this->status = 'disconnected';
        $this->disconnected_at = date('Y-m-d H:i:s');
        return $this->save();
    }

    /**
     * Mark the connection as completed.
     *
     * @return bool True on success, false on failure.
     */
    public function complete(): bool
    {
        $this->status = 'completed';
        $this->disconnected_at = date('Y-m-d H:i:s');
        return $this->save();
    }

    /**
     * Count active connections for a P2P token.
     *
     * @param int $tokenId P2P token ID.
     * @return int Number of active connections.
     */
    public static function countActiveByTokenId(int $tokenId): int
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM p2p_connections WHERE p2p_token_id = ? AND status = ?');
        $stmt->execute([$tokenId, 'connected']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int) ($row['count'] ?? 0);
    }
}
