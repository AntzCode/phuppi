<?php

/**
 * 014_encrypt_storage_keys.php
 *
 * Migration to encrypt existing storage connector API keys.
 * This migration encrypts plaintext 'key' and 'secret' fields in storage_connectors table.
 *
 * @package Phuppi\Migrations
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.6.0
 */

use Phuppi\Helper\EncryptionHelper;

// Check if master key is available
if (!EncryptionHelper::isSecureModeAvailable()) {
    Flight::logger()->info('Migration 014: Master key not available, skipping key encryption');
    return;
}

// Get all S3/DO Spaces connectors
$db = Flight::db();
$stmt = $db->query("SELECT name, config FROM storage_connectors WHERE type IN ('s3', 'do_spaces')");
$connectors = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($connectors)) {
    Flight::logger()->info('Migration 014: No S3/DO Spaces connectors found');
    return;
}

$encryptedCount = 0;

foreach ($connectors as $connector) {
    $config = json_decode($connector['config'], true);

    // Skip if already encrypted
    if (isset($config['keys_encrypted']) && $config['keys_encrypted']) {
        continue;
    }

    // Skip if keys are empty
    if (empty($config['key']) || empty($config['secret'])) {
        continue;
    }

    // Encrypt the keys
    $encryptedKey = EncryptionHelper::encrypt($config['key']);
    $encryptedSecret = EncryptionHelper::encrypt($config['secret']);

    if ($encryptedKey !== false && $encryptedSecret !== false) {
        $config['key'] = $encryptedKey;
        $config['secret'] = $encryptedSecret;
        $config['keys_encrypted'] = true;

        $updateStmt = $db->prepare('UPDATE storage_connectors SET config = ? WHERE name = ?');
        $updateStmt->execute([json_encode($config), $connector['name']]);
        $encryptedCount++;

        Flight::logger()->info('Migration 014: Encrypted keys for connector: ' . $connector['name']);
    } else {
        Flight::logger()->warning('Migration 014: Failed to encrypt keys for connector: ' . $connector['name']);
    }
}

Flight::logger()->info("Migration 014: Encrypted $encryptedCount connector(s)");