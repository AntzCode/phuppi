<?php

$db = Flight::db();

Flight::logger()->info('Creating storage_connectors table...');

// Storage connectors table
$db->exec("CREATE TABLE storage_connectors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL,
    config TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

Flight::logger()->info('Storage connectors table created.');

// Record migration
$db->exec("INSERT INTO migrations (name) VALUES ('002_create_storage_connectors_table')");

Flight::logger()->info('Migration 002_create_storage_connectors_table completed successfully.');