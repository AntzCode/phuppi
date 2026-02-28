<?php

/**
 * 003_add_short_links.php
 *
 * Migration to add short_links table for URL shortener.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.1
 */

$db = Flight::db();

$db->exec("CREATE TABLE IF NOT EXISTS short_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shortcode VARCHAR(8) NOT NULL UNIQUE,
    target TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL
);");

// Flight::logger()->info('Migration 003_add_short_links completed.');
