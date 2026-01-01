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

        // Run pending migrations
        self::run();
    }

    public static function run(): void
    {
        $db = Flight::db();
        $migrationsPath = __DIR__ . '/../migrations';

        if (!is_dir($migrationsPath)) {
            return;
        }

        // Get executed migrations
        $executed = $db->query("SELECT name FROM migrations")->fetchAll(\PDO::FETCH_COLUMN, 0);
        $executed = array_flip($executed);

        // Get migration files
        $files = glob($migrationsPath . '/*.php');
        sort($files);

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!isset($executed[$name])) {
                Flight::logger()->info("Running migration: $name");
                require_once $file;
                Flight::logger()->info("Migration completed: $name");
            }
        }
    }
}