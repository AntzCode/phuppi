<?php

/**
 * 005_add_remember_tokens.php
 *
 * Migration script for adding remember tokens table to support "Remember Me" functionality.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

$db = Flight::db();

Flight::logger()->info('Starting Remember Tokens Migration...');

// Check if table already exists
$tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='remember_tokens'")->fetchColumn();

if ($tableExists) {
    // Check if table has old schema (with expires_at)
    $columns = $db->query("PRAGMA table_info(remember_tokens)")->fetchAll(PDO::FETCH_ASSOC);
    $hasExpiresAt = false;
    $hasRevokedAt = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'expires_at') {
            $hasExpiresAt = true;
        }
        if ($column['name'] === 'revoked_at') {
            $hasRevokedAt = true;
        }
    }

    // If table has old schema (expires_at but no revoked_at), drop and recreate
    if ($hasExpiresAt && !$hasRevokedAt) {
        Flight::logger()->info('Dropping old remember_tokens table and recreating with new schema.');
        $db->exec("DROP TABLE remember_tokens");
        $tableExists = false;
    }
}

if (!$tableExists) {
    // Create remember_tokens table for persistent authentication (no expiration - tokens persist until revoked)
    $db->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        cookie_name VARCHAR(100) NOT NULL DEFAULT 'phuppi_remember',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_agent VARCHAR(500) NULL,
        ip_address VARCHAR(45) NULL,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    )");

    // Create index for faster lookups
    $db->exec("CREATE INDEX IF NOT EXISTS idx_remember_tokens_user_id ON remember_tokens(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_remember_tokens_revoked_at ON remember_tokens(revoked_at)");
}

Flight::logger()->info('Remember Tokens Migration completed successfully.');