<?php

/**
 * P2PShareToken.php
 *
 * P2PShareToken class for managing P2P share tokens in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd https://www.antzcode.com
 * @license GPLv3
 * @link https://github.com/AntzCode
 * @since 2.0.0
 */

namespace Phuppi;

use Flight;

class P2PShareToken
{
    /** @var int|null Primary key */
    public $id;

    /** @var int|null User ID who created the share */
    public $user_id;

    /** @var string 64-character cryptographically secure token */
    public $token;

    /** @var string 12-character alphanumeric shortcode */
    public $shortcode;

    /** @var string|null PeerJS ID for P2P connection */
    public $peerjs_id;

    /** @var string 2-digit PIN (10-99) */
    public $pin;

    /** @var int Number of failed PIN attempts */
    public $pin_attempts;

    /** @var string|null Timestamp when PIN was locked */
    public $pin_locked_at;

    /** @var string JSON-encoded array of file metadata */
    public $files_metadata;

    /** @var string|null Creation timestamp */
    public $created_at;

    /** @var string|null Expiration timestamp */
    public $expires_at;

    /** @var bool Whether the share is active */
    public $is_active;

    /**
     * Constructor for P2PShareToken.
     *
     * @param array $data Initial data to populate the object.
     */
    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->user_id = $data['user_id'] ?? null;
        $this->token = $data['token'] ?? '';
        $this->shortcode = $data['shortcode'] ?? '';
        $this->peerjs_id = $data['peerjs_id'] ?? null;
        $this->pin = $data['pin'] ?? '';
        $this->pin_attempts = $data['pin_attempts'] ?? 0;
        $this->pin_locked_at = $data['pin_locked_at'] ?? null;
        $this->files_metadata = $data['files_metadata'] ?? '[]';
        $this->created_at = $data['created_at'] ?? null;
        $this->expires_at = $data['expires_at'] ?? null;
        $this->is_active = $data['is_active'] ?? true;
    }

    /**
     * Generates a cryptographically secure 64-character token.
     *
     * @return string The generated token.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generates a 12-character alphanumeric shortcode.
     *
     * @return string The generated shortcode.
     */
    public static function generateShortcode(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $shortcode = '';
        $max = strlen($characters) - 1;
        
        for ($i = 0; $i < 12; $i++) {
            $shortcode .= $characters[random_int(0, $max)];
        }
        
        return $shortcode;
    }

    /**
     * Generates a 2-digit PIN (10-99).
     *
     * @return string The generated PIN.
     */
    public static function generatePin(): string
    {
        return str_pad(random_int(10, 99), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Creates a new P2P share token with generated token, shortcode, and PIN.
     *
     * @param int $user_id The user ID creating the share.
     * @param string $filesMetadata JSON-encoded file metadata.
     * @param string|null $expiresAt Expiration timestamp (default: 7 days from now).
     * @return self The created P2PShareToken instance.
     */
    public static function create(int $user_id, string $filesMetadata, ?string $expiresAt = null): self
    {
        $token = new self();
        $token->user_id = $user_id;
        $token->token = self::generateToken();
        $token->shortcode = self::generateShortcode();
        $token->pin = self::generatePin();
        $token->files_metadata = $filesMetadata;
        $token->expires_at = $expiresAt ?? date('Y-m-d H:i:s', strtotime('+7 days'));
        $token->is_active = true;
        
        return $token;
    }

    /**
     * Finds a token by its unique token string.
     *
     * @param string $token The token string.
     * @return self|null The token object if found, null otherwise.
     */
    public static function findByToken(string $token): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_shared_files WHERE token = ? AND is_active = 1');
        $stmt->execute([$token]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $data ? new self($data) : null;
    }

    /**
     * Finds a token by its shortcode.
     *
     * @param string $shortcode The shortcode.
     * @return self|null The token object if found, null otherwise.
     */
    public static function findByShortcode(string $shortcode): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_shared_files WHERE shortcode = ? AND is_active = 1');
        $stmt->execute([$shortcode]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $data ? new self($data) : null;
    }

    /**
     * Finds a token by its primary key ID.
     *
     * @param int $id The ID.
     * @return self|null The token object if found, null otherwise.
     */
    public static function findById(int $id): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_shared_files WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $data ? new self($data) : null;
    }

    /**
     * Finds a token by its PeerJS ID.
     *
     * @param string $peerjsId The PeerJS ID.
     * @return self|null The token object if found, null otherwise.
     */
    public static function findByPeerjsId(string $peerjsId): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_shared_files WHERE peerjs_id = ? AND is_active = 1');
        $stmt->execute([$peerjsId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $data ? new self($data) : null;
    }

    /**
     * Finds all active (not expired) tokens for a user.
     *
     * @param int $userId The user ID.
     * @return array Array of self objects.
     */
    public static function findActiveByUserId(int $userId): array
    {
        $db = Flight::db();
        $now = date('Y-m-d H:i:s');
        
        $stmt = $db->prepare('
            SELECT * FROM p2p_shared_files 
            WHERE user_id = ? 
            AND is_active = 1 
            AND expires_at > ?
            ORDER BY created_at DESC
        ');
        $stmt->execute([$userId, $now]);
        
        $tokens = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $tokens[] = new self($row);
        }
        
        return $tokens;
    }

    /**
     * Loads a token by its ID.
     *
     * @param int $id The ID of the token.
     * @return bool True if loaded successfully, false otherwise.
     */
    public function load(int $id): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_shared_files WHERE id = ?');
        $stmt->execute([$id]);
        
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($data) {
            $this->id = $data['id'];
            $this->user_id = $data['user_id'];
            $this->token = $data['token'];
            $this->shortcode = $data['shortcode'];
            $this->peerjs_id = $data['peerjs_id'];
            $this->pin = $data['pin'];
            $this->pin_attempts = $data['pin_attempts'];
            $this->pin_locked_at = $data['pin_locked_at'];
            $this->files_metadata = $data['files_metadata'];
            $this->created_at = $data['created_at'];
            $this->expires_at = $data['expires_at'];
            $this->is_active = (bool) $data['is_active'];
            return true;
        }
        return false;
    }

    /**
     * Saves the token to the database.
     *
     * @return bool True if saved successfully, false otherwise.
     */
    public function save(): bool
    {
        $db = Flight::db();
        if ($this->id) {
            // Update
            $stmt = $db->prepare('UPDATE p2p_shared_files SET user_id = ?, token = ?, shortcode = ?, peerjs_id = ?, pin = ?, pin_attempts = ?, pin_locked_at = ?, files_metadata = ?, expires_at = ?, is_active = ? WHERE id = ?');
            return $stmt->execute([
                $this->user_id,
                $this->token,
                $this->shortcode,
                $this->peerjs_id,
                $this->pin,
                $this->pin_attempts,
                $this->pin_locked_at,
                $this->files_metadata,
                $this->expires_at,
                $this->is_active ? 1 : 0,
                $this->id
            ]);
        } else {
            // Insert
            $stmt = $db->prepare('INSERT INTO p2p_shared_files (user_id, token, shortcode, peerjs_id, pin, pin_attempts, pin_locked_at, files_metadata, created_at, expires_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            $result = $stmt->execute([
                $this->user_id,
                $this->token,
                $this->shortcode,
                $this->peerjs_id,
                $this->pin,
                $this->pin_attempts,
                $this->pin_locked_at,
                $this->files_metadata,
                $now,
                $this->expires_at,
                $this->is_active ? 1 : 0
            ]);
            if ($result) {
                $this->id = $db->lastInsertId();
                $this->created_at = $now;
            }
            return $result;
        }
    }

    /**
     * Deletes the token (soft delete - sets is_active = 0).
     *
     * @return bool True if deleted successfully, false otherwise.
     */
    public function delete(): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('UPDATE p2p_shared_files SET is_active = 0 WHERE id = ?');
        if ($stmt->execute([$this->id])) {
            $this->is_active = false;
            return true;
        }
        return false;
    }

    /**
     * Checks if the token is expired.
     *
     * @return bool True if expired, false otherwise.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at) {
            return strtotime($this->expires_at) < time();
        }
        return false;
    }

    /**
     * Checks if the PIN is locked out.
     *
     * @return bool True if locked, false otherwise.
     */
    public function isLocked(): bool
    {
        if ($this->pin_locked_at) {
            // Check if lock has expired (15 minutes)
            $lockExpires = strtotime($this->pin_locked_at) + (15 * 60);
            if (time() > $lockExpires) {
                // Lock has expired, reset attempts
                $this->pin_attempts = 0;
                $this->pin_locked_at = null;
                $this->save();
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Increments the failed PIN attempts counter.
     *
     * @return self Returns $this for method chaining.
     */
    public function incrementPinAttempts(): self
    {
        $this->pin_attempts++;
        $db = Flight::db();
        $stmt = $db->prepare('UPDATE p2p_shared_files SET pin_attempts = ? WHERE id = ?');
        $stmt->execute([$this->pin_attempts, $this->id]);
        return $this;
    }

    /**
     * Locks the PIN after 3 failed attempts.
     *
     * @return self Returns $this for method chaining.
     */
    public function lock(): self
    {
        $this->pin_locked_at = date('Y-m-d H:i:s');
        $db = Flight::db();
        $stmt = $db->prepare('UPDATE p2p_shared_files SET pin_locked_at = ? WHERE id = ?');
        $stmt->execute([$this->pin_locked_at, $this->id]);
        return $this;
    }

    /**
     * Verifies the provided PIN against the stored PIN.
     *
     * @param string $pin The PIN to verify.
     * @return bool True if PIN is correct, false otherwise.
     */
    public function verifyPin(string $pin): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        if ($this->pin === $pin) {
            // Reset attempts on successful verification
            if ($this->pin_attempts > 0) {
                $this->pin_attempts = 0;
                $this->pin_locked_at = null;
                $this->save();
            }
            return true;
        }

        // Increment failed attempts
        $this->incrementPinAttempts();

        // Lock after 3 failed attempts
        if ($this->pin_attempts >= 3) {
            $this->lock();
        }

        return false;
    }

    /**
     * Gets the files metadata as a decoded JSON array.
     *
     * @return array<int, array{name: string, size: int, type: string}> The decoded files metadata.
     */
    public function getFilesMetadata(): array
    {
        return json_decode($this->files_metadata, true) ?? [];
    }

    /**
     * Calculates the total size of all files in the share.
     *
     * @return int The total size in bytes.
     */
    public function getTotalSize(): int
    {
        $files = $this->getFilesMetadata();
        $total = 0;
        
        foreach ($files as $file) {
            $total += $file['size'] ?? 0;
        }
        
        return $total;
    }

    /**
     * Gets the user who created the share.
     *
     * @return User|null The User object if found, null otherwise.
     */
    public function getUser(): ?User
    {
        if ($this->user_id) {
            $user = new User();
            if ($user->load($this->user_id)) {
                return $user;
            }
        }
        return null;
    }
}
