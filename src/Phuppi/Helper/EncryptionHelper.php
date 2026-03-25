<?php

/**
 * EncryptionHelper.php
 *
 * EncryptionHelper class for master key generation and secure storage of API keys.
 * Provides AES-256-GCM encryption for storage connector credentials.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.6.0
 */

namespace Phuppi\Helper;

use Flight;

class EncryptionHelper
{
    /** @var string|null Cached master key */
    private static ?string $masterKey = null;

    /** @var string Key file path */
    private const KEY_FILE_PATH = 'data/storage-key.key';

    /** @var string Environment variable name */
    private const ENV_VAR_NAME = 'PHUPPI_STORAGE_KEY_MASTER_KEY';

    /** @var int Key length in bytes (256 bits) */
    private const KEY_LENGTH = 32;

    /** @var int IV length in bytes (12 bytes for GCM) */
    private const IV_LENGTH = 12;

    /** @var int Auth tag length in bytes (16 bytes) */
    private const AUTH_TAG_LENGTH = 16;

    /**
     * Generates a new cryptographically secure master key.
     *
     * @return string Base64 encoded master key
     */
    public static function generateMasterKey(): string
    {
        return bin2hex(random_bytes(self::KEY_LENGTH));
    }

    /**
     * Gets the master key from environment variable or key file.
     * Caches the key for subsequent calls.
     *
     * @return string|null The master key or null if not available
     */
    public static function getMasterKey(): ?string
    {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }

        // First, check environment variable
        $envKey = getenv(self::ENV_VAR_NAME);
        if ($envKey !== false && !empty($envKey)) {
            self::$masterKey = $envKey;
            return self::$masterKey;
        }

        // Second, check key file
        $keyFile = self::getKeyFilePath();
        if (file_exists($keyFile) && is_readable($keyFile)) {
            $keyContent = file_get_contents($keyFile);
            if ($keyContent !== false && !empty(trim($keyContent))) {
                self::$masterKey = trim($keyContent);
                return self::$masterKey;
            }
        }

        return null;
    }

    /**
     * Saves the generated key to the key file.
     *
     * @param string $key The key to save
     * @return bool True if successful, false otherwise
     */
    public static function saveKeyToFile(string $key): bool
    {
        $keyFile = self::getKeyFilePath();
        $dir = dirname($keyFile);

        // Ensure directory exists
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                Flight::logger()->error('EncryptionHelper: Failed to create key directory: ' . $dir);
                return false;
            }
        }

        // Save key with restrictive permissions
        $result = file_put_contents($keyFile, $key, LOCK_EX);
        if ($result !== false) {
            chmod($keyFile, 0600);
            Flight::logger()->info('EncryptionHelper: Master key saved to file');
            return true;
        }

        Flight::logger()->error('EncryptionHelper: Failed to save master key to file');
        return false;
    }

    /**
     * Encrypts data using AES-256-GCM.
     *
     * @param string $data The data to encrypt
     * @param string|null $key Optional key (uses master key if not provided)
     * @return string|false The encrypted data (base64 encoded) or false on failure
     */
    public static function encrypt(string $data, ?string $key = null)
    {
        $key = $key ?? self::getMasterKey();
        if ($key === null) {
            Flight::logger()->error('EncryptionHelper: Cannot encrypt - no master key available');
            return false;
        }

        // Decode the hex key to binary
        $keyBinary = hex2bin($key);
        if ($keyBinary === false) {
            Flight::logger()->error('EncryptionHelper: Invalid key format');
            return false;
        }

        // Generate random IV
        $iv = random_bytes(self::IV_LENGTH);
        if ($iv === false) {
            Flight::logger()->error('EncryptionHelper: Failed to generate IV');
            return false;
        }

        // Encrypt using AES-256-GCM
        $ciphertext = openssl_encrypt(
            $data,
            'aes-256-gcm',
            $keyBinary,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            Flight::logger()->error('EncryptionHelper: Encryption failed: ' . openssl_error_string());
            return false;
        }

        // Combine IV + ciphertext + auth tag and base64 encode
        $encrypted = $iv . $ciphertext . $tag;
        return base64_encode($encrypted);
    }

    /**
     * Decrypts data using AES-256-GCM.
     *
     * @param string $encryptedData The encrypted data (base64 encoded)
     * @param string|null $key Optional key (uses master key if not provided)
     * @return string|false The decrypted data or false on failure
     */
    public static function decrypt(string $encryptedData, ?string $key = null)
    {
        $key = $key ?? self::getMasterKey();
        if ($key === null) {
            Flight::logger()->error('EncryptionHelper: Cannot decrypt - no master key available');
            return false;
        }

        // First check if it looks like valid base64 - if not, it's probably plaintext
        $data = base64_decode($encryptedData, true);
        if ($data === false) {
            // Not valid base64 - likely plaintext, return as-is
            return $encryptedData;
        }

        // Check minimum length for encrypted data (IV + auth tag + at least 1 byte)
        if (strlen($data) < self::IV_LENGTH + self::AUTH_TAG_LENGTH + 1) {
            // Too short to be encrypted - probably plaintext
            return $encryptedData;
        }

        // Decode the hex key to binary
        $keyBinary = hex2bin($key);
        if ($keyBinary === false) {
            Flight::logger()->error('EncryptionHelper: Invalid key format');
            return false;
        }

        // Extract IV, ciphertext, and auth tag
        $iv = substr($data, 0, self::IV_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH, -self::AUTH_TAG_LENGTH);
        $tag = substr($data, -self::AUTH_TAG_LENGTH);

        if (strlen($iv) !== self::IV_LENGTH || strlen($tag) !== self::AUTH_TAG_LENGTH) {
            // Invalid structure - treat as plaintext
            return $encryptedData;
        }

        // Decrypt
        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $keyBinary,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            // Decryption failed - likely wrong key or corrupted data, return original
            Flight::logger()->warning('EncryptionHelper: Decryption failed, treating as plaintext');
            return $encryptedData;
        }

        return $decrypted;
    }

    /**
     * Checks if secure mode is available (master key is configured).
     *
     * @return bool True if secure mode is available
     */
    public static function isSecureModeAvailable(): bool
    {
        return self::getMasterKey() !== null;
    }

    /**
     * Gets the key file path.
     *
     * @return string The full path to the key file
     */
    public static function getKeyFilePath(): string
    {
        $dataPath = Flight::get('flight.data.path') ?: dirname(__DIR__, 2) . '/data';
        return $dataPath . '/' . self::KEY_FILE_PATH;
    }

    /**
     * Checks if there are any S3/DO Spaces connectors configured.
     *
     * @return bool True if there are S3-type connectors
     */
    public static function hasS3Connectors(): bool
    {
        $connectors = Flight::get('storage_connectors') ?? [];
        foreach ($connectors as $connector) {
            $type = $connector['type'] ?? '';
            if ($type === 's3' || $type === 'do_spaces') {
                return true;
            }
        }
        return false;
    }

    /**
     * Encrypts storage connector keys in config.
     *
     * @param array $config The connector config
     * @return array The config with encrypted keys
     */
    public static function encryptConnectorKeys(array $config): array
    {
        if (!self::isSecureModeAvailable()) {
            return $config;
        }

        // Encrypt key and secret if they exist and are not already encrypted
        if (isset($config['key']) && !empty($config['key'])) {
            $encryptedKey = self::encrypt($config['key']);
            if ($encryptedKey !== false) {
                $config['key'] = $encryptedKey;
                $config['keys_encrypted'] = true;
            }
        }

        if (isset($config['secret']) && !empty($config['secret'])) {
            $encryptedSecret = self::encrypt($config['secret']);
            if ($encryptedSecret !== false) {
                $config['secret'] = $encryptedSecret;
            }
        }

        return $config;
    }

    /**
     * Decrypts storage connector keys in config.
     *
     * @param array $config The connector config
     * @return array The config with decrypted keys
     */
    public static function decryptConnectorKeys(array $config): array
    {
        if (!self::isSecureModeAvailable()) {
            return $config;
        }

        // Check if keys are encrypted
        $isEncrypted = $config['keys_encrypted'] ?? false;

        if ($isEncrypted) {
            if (isset($config['key']) && !empty($config['key'])) {
                $decryptedKey = self::decrypt($config['key']);
                if ($decryptedKey !== false) {
                    $config['key'] = $decryptedKey;
                }
            }

            if (isset($config['secret']) && !empty($config['secret'])) {
                $decryptedSecret = self::decrypt($config['secret']);
                if ($decryptedSecret !== false) {
                    $config['secret'] = $decryptedSecret;
                }
            }
        }

        return $config;
    }

    /**
     * Re-encrypts all storage connector keys with a new master key.
     * Called when regenerating the master key.
     *
     * @return bool True if successful
     */
    public static function reEncryptAllConnectorKeys(): bool
    {
        $db = Flight::db();
        
        // Get current key (before regeneration) to decrypt existing keys
        $oldKey = self::getMasterKey();
        
        if ($oldKey === null) {
            Flight::logger()->warning('EncryptionHelper: No master key available for re-encryption');
            return false;
        }

        // Get all S3/DO Spaces connectors
        $stmt = $db->query("SELECT name, config FROM storage_connectors WHERE type IN ('s3', 'do_spaces')");
        $connectors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($connectors)) {
            return true;
        }

        $reencryptedCount = 0;

        foreach ($connectors as $connector) {
            $config = json_decode($connector['config'], true);

            // Skip if not encrypted (plaintext keys)
            if (!isset($config['keys_encrypted']) || !$config['keys_encrypted']) {
                continue;
            }

            // Decrypt with OLD key (use the key we had before regeneration)
            $plainKey = isset($config['key']) ? self::decrypt($config['key'], $oldKey) : false;
            $plainSecret = isset($config['secret']) ? self::decrypt($config['secret'], $oldKey) : false;

            // If decryption failed (wrong key), skip this connector
            if ($plainKey === false || $plainSecret === false) {
                Flight::logger()->warning('EncryptionHelper: Failed to decrypt keys for connector: ' . $connector['name']);
                continue;
            }

            // Now re-encrypt with NEW key (which should be cached after generation)
            $config['key'] = self::encrypt($plainKey);
            $config['secret'] = self::encrypt($plainSecret);

            if ($config['key'] !== false && $config['secret'] !== false) {
                $updateStmt = $db->prepare('UPDATE storage_connectors SET config = ? WHERE name = ?');
                $updateStmt->execute([json_encode($config), $connector['name']]);
                $reencryptedCount++;

                Flight::logger()->info('EncryptionHelper: Re-encrypted keys for connector: ' . $connector['name']);
            }
        }

        Flight::logger()->info("EncryptionHelper: Re-encrypted $reencryptedCount connector(s)");

        return true;
    }

    /**
     * Clears the cached master key (useful for testing).
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$masterKey = null;
    }
}