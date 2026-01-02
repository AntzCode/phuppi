<?php

/**
 * DatabaseSession.php
 *
 * DatabaseSession class for database-backed session handling in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

declare(strict_types=1);

namespace Phuppi;

use PDO;
use SessionHandlerInterface;

/**
 * A database-backed session handler for the Flight framework.
 * Stores session data in a database table instead of files.
 */
class DatabaseSession implements SessionHandlerInterface
{
    /** @var PDO */
    private PDO $db;

    /** @var string */
    private string $table;

    /** @var array */
    private array $data = [];

    /** @var bool */
    private bool $changed = false;

    /** @var ?string */
    private ?string $sessionId = null;

    /** @var bool */
    private bool $autoCommit = true;

    /** @var bool */
    private bool $testMode = false;

    /** @var ?string 'json' (default) or 'php' */
    private ?string $serialization = null;

    /**
     * Constructor to initialize the database session handler.
     *
     * @param PDO $db PDO database connection
     * @param array $config Configuration options:
     *      - table: Table name for sessions (default: 'sessions')
     *      - auto_commit: Whether to auto-commit session changes on shutdown (default: true)
     *      - test_mode: Run in test mode without altering PHP's session state (default: false)
     *      - serialization: 'json' or 'php' (default: 'json')
     *      - start_session: Whether to start the session automatically (default: true)
     */
    public function __construct(PDO $db, array $config = [])
    {
        $this->db = $db;
        $this->table = $config['table'] ?? 'sessions';
        $this->autoCommit = $config['auto_commit'] ?? true;
        $this->testMode = $config['test_mode'] ?? false;
        $this->serialization = $config['serialization'] ?? 'json';
        $startSession = $config['start_session'] ?? true;

        if (!in_array($this->serialization, ['json', 'php'], true)) {
            throw new \InvalidArgumentException("Invalid serialization method: {$this->serialization}. Use 'json' or 'php'.");
        }

        // Initialize session handler
        $this->initializeSession($startSession);

        // Register auto-commit on shutdown if enabled
        if ($this->autoCommit === true) {
            register_shutdown_function([$this, 'commit']);
        }
    }

    /**
     * Initialize the session handler and optionally start the session.
     *
     * @param bool $startSession Whether to start the session automatically
     * @return void
     */
    private function initializeSession(bool $startSession): void
    {
        if ($startSession === true && session_status() === PHP_SESSION_NONE) {
            session_set_save_handler($this, true);
            session_start([
                'use_strict_mode' => true,
                'use_cookies' => 1,
                'use_only_cookies' => 1,
                'cookie_httponly' => 1
            ]);
            $this->sessionId = session_id();
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            $this->sessionId = session_id();
        }
    }

    /**
     * Open a session.
     *
     * @param string $savePath The path to save the session.
     * @param string $sessionName The name of the session.
     * @return bool Always returns true.
     */
    public function open($savePath, $sessionName): bool
    {
        return true;
    }

    /**
     * Closes the current session.
     *
     * @return bool Always returns true.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Reads the session data associated with the given session ID.
     *
     * @param string $id The session ID.
     * @return string The session data.
     */
    #[\ReturnTypeWillChange]
    public function read($id): string
    {
        $this->sessionId = $id;
        $stmt = $this->db->prepare("SELECT session_data FROM {$this->table} WHERE session_id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetchColumn();

        if ($data === false) {
            $this->data = [];
            return '';
        }

        if ($this->serialization === 'json') {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->data = $decoded;
                return '';
            }
        } elseif ($this->serialization === 'php') {
            $unserialized = unserialize($data);
            if ($unserialized !== false) {
                $this->data = $unserialized;
                return '';
            }
        }

        // Fail fast: corruption
        $this->data = [];
        return '';
    }

    /**
     * Writes the session data.
     *
     * @param string $id The session ID.
     * @param string $data The session data.
     * @return bool True if written successfully, false otherwise.
     */
    public function write($id, $data): bool
    {
        if ($this->changed !== true && !empty($this->data)) {
            return true;
        }

        $serialized = '';
        if ($this->serialization === 'json') {
            if (!empty($this->data)) {
                $this->assertNoObjects($this->data);
            }
            $serialized = json_encode($this->data);
            if ($serialized === false) {
                return false;
            }
        } elseif ($this->serialization === 'php') {
            $serialized = serialize($this->data);
        }

        $stmt = $this->db->prepare("INSERT OR REPLACE INTO {$this->table} (session_id, session_data, updated_at) VALUES (?, ?, datetime('now'))");
        return $stmt->execute([$id, $serialized]);
    }

    /**
     * Destroys the session with the given ID.
     *
     * @param string $id The session ID.
     * @return bool True if destroyed successfully, false otherwise.
     */
    public function destroy($id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE session_id = ?");
        $result = $stmt->execute([$id]);

        if ($id === $this->sessionId) {
            $this->data = [];
            $this->changed = true;
            $this->autoCommit = false;
            $this->commit();
            if ($this->testMode === false && session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $this->sessionId = null;
        }

        return $result;
    }

    /**
     * Garbage collector for session data.
     *
     * @param int $maxLifetime The maximum lifetime of a session.
     * @return int The number of deleted sessions.
     */
    #[\ReturnTypeWillChange]
    public function gc($maxLifetime)
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE updated_at < datetime('now', '-{$maxLifetime} seconds')");
        return $stmt->execute();
    }

    /**
     * Sets a session variable.
     *
     * @param string $key The key.
     * @param mixed $value The value.
     * @return self
     */
    public function set(string $key, $value): self
    {
        $this->data[$key] = $value;
        $this->changed = true;
        return $this;
    }

    /**
     * Retrieve a value from the session.
     *
     * @param string $key The key.
     * @param mixed $default The default value.
     * @return mixed The value or default.
     */
    public function get(string $key, $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Deletes a session variable.
     *
     * @param string $key The key.
     * @return self
     */
    public function delete(string $key): self
    {
        unset($this->data[$key]);
        $this->changed = true;
        return $this;
    }

    /**
     * Clears all session data.
     *
     * @return self
     */
    public function clear(): self
    {
        $this->data = [];
        $this->changed = true;
        return $this;
    }

    /**
     * Retrieve all session data.
     *
     * @return array The session data.
     */
    public function getAll(): array
    {
        return $this->data;
    }

    /**
     * Commits the current session data.
     *
     * @return void
     */
    public function commit(): void
    {
        if ($this->changed && $this->sessionId) {
            $this->write($this->sessionId, '');
            $this->changed = false;
        }
    }

    /**
     * Get the current session ID.
     *
     * @return ?string The session ID.
     */
    public function id(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Regenerates the session ID.
     *
     * @param bool $deleteOldFile Whether to delete the old session file.
     * @return self
     */
    public function regenerate(bool $deleteOldFile = false): self
    {
        if ($this->sessionId) {
            $oldId = $this->sessionId;
            $oldData = $this->data;
            $this->sessionId = bin2hex(random_bytes(16));
            $this->changed = true;

            if ($deleteOldFile) {
                $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE session_id = ?");
                $stmt->execute([$oldId]);
            }

            // Save the current data with the new session ID
            if (!empty($oldData)) {
                $this->data = $oldData;
                $this->commit();
            }
        }
        return $this;
    }

    /**
     * Recursively check for objects in data (for JSON safety).
     *
     * @param mixed $data The data to check.
     * @return void
     * @throws \InvalidArgumentException If objects are found.
     */
    private function assertNoObjects($data): void
    {
        $stack = [$data];
        while ($stack) {
            $current = array_pop($stack);
            foreach ($current as $v) {
                if (is_object($v) === true) {
                    throw new \InvalidArgumentException('Session data contains an object, which cannot be safely stored with JSON serialization.');
                } elseif (is_array($v) === true) {
                    $stack[] = $v;
                }
            }
        }
    }
}
