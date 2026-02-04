<?php

/**
 * ShortLink.php
 *
 * ShortLink class for managing short URLs in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.1
 */

namespace Phuppi;

use Flight;

class ShortLink
{
    /** @var int|null */
    public $id;

    /** @var string */
    public $shortcode;

    /** @var string */
    public $target;

    /** @var string|null */
    public $created_at;

    /** @var string|null */
    public $expires_at;

    /**
     * Constructor for ShortLink.
     *
     * @param array $data Initial data to populate the object.
     */
    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->shortcode = $data['shortcode'] ?? '';
        $this->target = $data['target'] ?? '';
        $this->created_at = $data['created_at'] ?? null;
        $this->expires_at = $data['expires_at'] ?? null;
    }

    /**
     * Loads a short link by its ID.
     *
     * @param int $id The ID of the short link.
     * @return bool True if loaded successfully, false otherwise.
     */
    public function load(int $id): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM short_links WHERE id = ?');
        $stmt->execute([$id]);
        
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($data) {
            $this->id = $data['id'];
            $this->shortcode = $data['shortcode'];
            $this->target = $data['target'];
            $this->created_at = $data['created_at'];
            $this->expires_at = $data['expires_at'];
            return true;
        }
        return false;
    }

    /**
     * Cleans up expired short links from the database.
     *
     * @return int The number of deleted short links.
     */
    public static function cleanupExpired(): int
    {
        $db = Flight::db();
        $stmt = $db->prepare("DELETE FROM short_links WHERE expires_at IS NOT NULL AND expires_at < datetime('now')");
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Finds a short link by its shortcode, cleaning up expired ones first.
     *
     * @param string $shortcode The shortcode.
     * @return self|null The short link object if found and not expired, null otherwise.
     */
    public static function findByShortcode(string $shortcode): ?self
    {
        // Clean up expired short links before searching
        self::cleanupExpired();

        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM short_links WHERE shortcode = ? AND (expires_at IS NULL OR expires_at > datetime("now"))');
        $stmt->execute([$shortcode]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $data ? new self($data) : null;
    }

    /**
     * Finds all short links for a user.
     *
     * @return self[] Array of short link objects.
     */
    public static function findAll(): array
    {
        $db = Flight::db();
        $stmt = $db->query('SELECT * FROM short_links ORDER BY created_at DESC');
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return array_map(fn($data) => new self($data), $results);
    }

    /**
     * Checks if the short link is expired.
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
     * Generates a random shortcode.
     *
     * @return string 8 character alphanumeric shortcode.
     */
    private function generateShortcode(): string
    {
        return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 5);
    }

    /**
     * Saves the short link to the database.
     *
     * @return bool True if saved successfully, false otherwise.
     */
    public function save(): bool
    {
        $db = Flight::db();
        if ($this->id) {
            // Update
            $stmt = $db->prepare('UPDATE short_links SET shortcode = ?, target = ?, expires_at = ? WHERE id = ?');
            return $stmt->execute([
                $this->shortcode,
                $this->target,
                $this->expires_at,
                $this->id
            ]);
        } else {
            // Insert with unique shortcode
            $now = date('Y-m-d H:i:s');
            do {
                $this->shortcode = $this->generateShortcode();
                $stmt = $db->prepare('INSERT OR IGNORE INTO short_links (shortcode, target, created_at, expires_at) VALUES (?, ?, ?, ?)');
                $result = $stmt->execute([
                    $this->shortcode,
                    $this->target,
                    $now,
                    $this->expires_at
                ]);
            } while (!$result);
            $this->id = $db->lastInsertId();
            $this->created_at = $now;
            return true;
        }
    }

    /**
     * Deletes the short link from the database.
     *
     * @return bool True if deleted successfully, false otherwise.
     */
    public function delete(): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM short_links WHERE id = ?');
        return $stmt->execute([$this->id]);
    }
}
