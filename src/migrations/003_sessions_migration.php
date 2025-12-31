<?php

require_once(dirname(__DIR__) . '/bootstrap.php');


// Migration to add database-backed sessions table

$db = Flight::db();

Flight::logger()->info('Starting Sessions Migration...');

// Create sessions table for database-backed session storage
$db->exec("CREATE TABLE IF NOT EXISTS sessions (
    session_id VARCHAR(255) PRIMARY KEY,
    session_data TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Create index on updated_at for garbage collection
$db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_updated_at ON sessions (updated_at)");

Flight::logger()->info('Sessions table created.');

// Record migration
$db->exec("INSERT INTO migrations (name) VALUES ('003_sessions_migration')");

Flight::logger()->info('Sessions Migration completed successfully.');