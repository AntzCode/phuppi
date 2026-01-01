<?php

namespace Phuppi;

use Flight;

class Migration
{
    public static function init(): void
    {
        $db = Flight::db();

        // Create migrations table if it doesn't exist
        $db->exec("CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Create sessions table for database-backed session storage
        $db->exec("CREATE TABLE IF NOT EXISTS sessions (
            session_id VARCHAR(255) PRIMARY KEY,
            session_data TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Create index on updated_at for garbage collection
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_updated_at ON sessions (updated_at)");

    }
}