<?php

/**
 * UploadedFileToken.php
 *
 * UploadedFileToken class for managing access tokens associated with uploaded files in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi;

use Flight;

class UploadedFileToken
{
    /** @var int|null */
    public $id;

    /** @var int|null */
    public $uploaded_file_id;

    /** @var int|null */
    public $voucher_id;

    /** @var string */
    public $token;

    /** @var string|null */
    public $created_at;

    /** @var string|null */
    public $expires_at;


    /**
     * Constructor for UploadedFileToken.
     *
     * @param array $data Initial data to populate the object.
     */
    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->uploaded_file_id = $data['uploaded_file_id'] ?? null;
        $this->voucher_id = $data['voucher_id'] ?? null;
        $this->token = $data['token'] ?? '';
        $this->created_at = $data['created_at'] ?? null;
        $this->expires_at = $data['expires_at'] ?? null;
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
        $stmt = $db->prepare('SELECT * FROM uploaded_file_tokens WHERE id = ?');
        $stmt->execute([$id]);
        
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($data) {
            $this->id = $data['id'];
            $this->uploaded_file_id = $data['uploaded_file_id'];
            $this->voucher_id = $data['voucher_id'];
            $this->token = $data['token'];
            $this->created_at = $data['created_at'];
            $this->expires_at = $data['expires_at'];
            return true;
        }
        return false;
    }

    /**
     * Cleans up expired tokens from the database.
     *
     * @return int The number of deleted tokens.
     */
    public static function cleanupExpired(): int
    {
        $db = Flight::db();
        $stmt = $db->prepare("DELETE FROM uploaded_file_tokens WHERE expires_at IS NOT NULL AND expires_at < datetime('now')");
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Finds a token by its token string, cleaning up expired ones first.
     *
     * @param string $token The token string.
     * @return self|null The token object if found and not expired, null otherwise.
     */
    public static function findByToken(string $token): ?self
    {
        // Clean up expired tokens before searching
        self::cleanupExpired();

        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM uploaded_file_tokens WHERE token = ? AND (expires_at IS NULL OR expires_at > datetime("now"))');
        $stmt->execute([$token]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $data ? new self($data) : null;
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
     * Saves the token to the database.
     *
     * @return bool True if saved successfully, false otherwise.
     */
    public function save(): bool
    {
        $db = Flight::db();
        if ($this->id) {
            // Update
            $stmt = $db->prepare('UPDATE uploaded_file_tokens SET uploaded_file_id = ?, voucher_id = ?, token = ?, expires_at = ? WHERE id = ?');
            return $stmt->execute([
                $this->uploaded_file_id,
                $this->voucher_id,
                $this->token,
                $this->expires_at,
                $this->id
            ]);
        } else {
            // Insert
            $stmt = $db->prepare('INSERT INTO uploaded_file_tokens (uploaded_file_id, voucher_id, token, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            $result = $stmt->execute([
                $this->uploaded_file_id,
                $this->voucher_id,
                $this->token,
                $now,
                $this->expires_at
            ]);
            if ($result) {
                $this->id = $db->lastInsertId();
                $this->created_at = $now;
            }
            return $result;
        }
    }

    /**
     * Deletes the token from the database.
     *
     * @return bool True if deleted successfully, false otherwise.
     */
    public function delete(): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM uploaded_file_tokens WHERE id = ?');
        return $stmt->execute([$this->id]);
    }
}
