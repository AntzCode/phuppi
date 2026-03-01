<?php

/**
 * RememberToken.php
 *
 * RememberToken class for managing persistent authentication tokens ("Remember Me" functionality).
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
use PDO;

class RememberToken
{
    /** @var int|null The remember token ID */
    public ?int $id = null;

    /** @var int The user ID this token belongs to */
    public int $user_id;

    /** @var string The hashed token (never store plain text) */
    public string $token_hash;

    /** @var string The cookie name */
    public string $cookie_name;

    /** @var string The creation datetime */
    public string $created_at;

    /** @var string|null The user agent string */
    public ?string $user_agent;

    /** @var string|null The IP address */
    public ?string $ip_address;

    /** @var string|null The last used datetime */
    public ?string $last_used_at;

    /** @var string|null The revocation datetime */
    public ?string $revoked_at = null;

    /** @var string|null The plain token (only available immediately after creation) */
    public ?string $plainToken = null;

    /** @var string Cookie name for remember token */
    private const COOKIE_NAME = 'phuppi_remember';

    /**
     * Creates a new remember token for a user.
     *
     * @param int $userId The user ID to create the token for
     * @return self The created remember token instance
     */
    public static function create(int $userId): self
    {
        $db = Flight::db();

        // Generate a cryptographically secure token
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        // Get request info for security validation
        $request = Flight::request();
        $userAgent = $request->getHeader('User-Agent') ?? $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        // Insert the token into the database (no expiration - tokens persist until revoked or deleted)
        $stmt = $db->prepare('
            INSERT INTO remember_tokens (user_id, token_hash, cookie_name, user_agent, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            $tokenHash,
            self::COOKIE_NAME,
            $userAgent,
            $ipAddress
        ]);

        $token = new self();
        $token->id = (int) $db->lastInsertId();
        $token->user_id = $userId;
        $token->token_hash = $tokenHash;
        $token->cookie_name = self::COOKIE_NAME;
        $token->created_at = date('Y-m-d H:i:s');
        $token->user_agent = $userAgent;
        $token->ip_address = $ipAddress;

        // Return both the token object and the plain token for cookie setting
        $token->plainToken = $plainToken;

        return $token;
    }

    /**
     * Validates a remember token from a cookie.
     *
     * @param string $plainToken The plain token from the cookie
     * @return self|null The remember token if valid, null otherwise
     */
    public static function validate(string $plainToken): ?self
    {
        $tokenHash = hash('sha256', $plainToken);
        $db = Flight::db();

        $stmt = $db->prepare('
            SELECT id, user_id, token_hash, cookie_name, created_at, user_agent, ip_address, last_used_at, revoked_at
            FROM remember_tokens
            WHERE token_hash = ?
        ');
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $token = new self();
        $token->id = (int) $row['id'];
        $token->user_id = (int) $row['user_id'];
        $token->token_hash = $row['token_hash'];
        $token->cookie_name = $row['cookie_name'];
        $token->created_at = $row['created_at'];
        $token->user_agent = $row['user_agent'];
        $token->ip_address = $row['ip_address'];
        $token->last_used_at = $row['last_used_at'];
        $token->revoked_at = $row['revoked_at'];

        // Check if token is revoked
        if ($token->isRevoked()) {
            Flight::logger()->info('Remember token was revoked for user ID: ' . $token->user_id);
            return null;
        }

        // Validate user agent for additional security
        $request = Flight::request();
        $currentUserAgent = $request->getHeader('User-Agent') ?? $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Allow some variation in user agent (e.g., different browser versions)
        // but check for major changes
        if ($token->user_agent !== null && $currentUserAgent !== null) {
            // Extract browser type from user agent for basic validation
            $tokenBrowser = self::extractBrowserFromUserAgent($token->user_agent);
            $currentBrowser = self::extractBrowserFromUserAgent($currentUserAgent);

            if ($tokenBrowser !== $currentBrowser) {
                Flight::logger()->warning('Remember token user agent mismatch: expected ' . $tokenBrowser . ', got ' . $currentBrowser);
                // Don't fail on user agent mismatch, just log it
            }
        }

        // Update last used timestamp
        $token->updateLastUsed();

        return $token;
    }

    /**
     * Extracts browser type from user agent string.
     *
     * @param string $userAgent The user agent string
     * @return string The browser type
     */
    private static function extractBrowserFromUserAgent(string $userAgent): string
    {
        if (stripos($userAgent, 'Firefox') !== false) {
            return 'Firefox';
        } elseif (stripos($userAgent, 'Chrome') !== false) {
            return 'Chrome';
        } elseif (stripos($userAgent, 'Safari') !== false) {
            return 'Safari';
        } elseif (stripos($userAgent, 'Edge') !== false) {
            return 'Edge';
        } elseif (stripos($userAgent, 'MSIE') !== false || stripos($userAgent, 'Trident') !== false) {
            return 'IE';
        }
        return 'Unknown';
    }

    /**
     * Checks if the token is revoked.
     *
     * @return bool True if revoked, false otherwise
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Revokes this token.
     *
     * @return bool True if revoked successfully, false otherwise
     */
    public function revoke(): bool
    {
        if ($this->id === null) {
            return false;
        }

        $db = Flight::db();
        $stmt = $db->prepare('UPDATE remember_tokens SET revoked_at = datetime("now") WHERE id = ?');
        $result = $stmt->execute([$this->id]);

        if ($result) {
            $this->revoked_at = date('Y-m-d H:i:s');
        }

        return $result;
    }

    /**
     * Updates the last used timestamp.
     *
     * @return void
     */
    private function updateLastUsed(): void
    {
        if ($this->id === null) {
            return;
        }

        $db = Flight::db();
        $stmt = $db->prepare('UPDATE remember_tokens SET last_used_at = datetime("now") WHERE id = ?');
        $stmt->execute([$this->id]);
        $this->last_used_at = date('Y-m-d H:i:s');
    }

    /**
     * Deletes this remember token from the database.
     *
     * @return bool True if deleted successfully, false otherwise
     */
    public function delete(): bool
    {
        if ($this->id === null) {
            return false;
        }

        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM remember_tokens WHERE id = ?');
        $result = $stmt->execute([$this->id]);

        if ($result) {
            $this->id = null;
        }

        return $result;
    }

    /**
     * Deletes all remember tokens for a specific user.
     *
     * @param int $userId The user ID
     * @return void
     */
    public static function deleteAllForUser(int $userId): void
    {
        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * Deletes all remember tokens for a user by username.
     *
     * @param string $username The username
     * @return void
     */
    public static function deleteAllForUsername(string $username): void
    {
        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM remember_tokens WHERE user_id = (SELECT id FROM users WHERE username = ?)');
        $stmt->execute([$username]);
    }

    /**
     * Cleans up expired remember tokens from the database.
     *
     * @return int The number of deleted tokens
     */
    /**
     * Sets the remember token cookie.
     *
     * @param string $plainToken The plain token to set in the cookie
     * @return void
     */
    public function setCookie(string $plainToken): void
    {
        // Set cookie to expire in 1 year (persistent session)
        $expiration = time() + (365 * 24 * 60 * 60);

        // Set secure cookie parameters
        $cookieParams = [
            'expires' => $expiration,
            'path' => '/',
            'domain' => '',
            'secure' => true, // HTTPS only in production
            'httponly' => true, // Prevent JavaScript access
            'samesite' => 'Lax' // CSRF protection
        ];

        // Check if we're in production (HTTPS)
        if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
            $cookieParams['secure'] = false;
        }

        setcookie(
            $this->cookie_name,
            $plainToken,
            $cookieParams
        );
    }

    /**
     * Clears the remember token cookie.
     *
     * @return void
     */
    public function clearCookie(): void
    {
        setcookie(
            $this->cookie_name,
            '',
            [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    /**
     * Gets the cookie name.
     *
     * @return string The cookie name
     */
    public function getCookieName(): string
    {
        return $this->cookie_name;
    }

    /**
     * Gets the user ID.
     *
     * @return int The user ID
     */
    public function getUserId(): int
    {
        return $this->user_id;
    }
}