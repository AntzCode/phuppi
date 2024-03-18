CREATE TABLE `fuppi_users` (
    `user_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `username` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `created_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `disabled_at` DATE NULL DEFAULT NULL,
    `session_expires_at` DATE NULL DEFAULT NULL,
    `notes` TEXT NOT NULL DEFAULT ''
);