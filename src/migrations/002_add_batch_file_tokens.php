<?php

/**
 * 002_add_batch_file_tokens.php
 *
 * Migration to add batch_file_tokens table for batch file sharing.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.1
 */

$db = Flight::db();

$db->exec("CREATE TABLE IF NOT EXISTS batch_file_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    voucher_id INTEGER NULL REFERENCES vouchers(id),
    file_ids TEXT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL
);");

Flight::logger()->info('Migration 002_add_batch_file_tokens completed.');
