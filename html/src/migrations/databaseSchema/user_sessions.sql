CREATE TABLE `fuppi_user_sessions` (
    `session_id` VARCHAR(255) NOT NULL PRIMARY KEY,
    `user_id` INTEGER NOT NULL,
    `session_expires_at` DATE NULL DEFAULT NULL,
    `last_login_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
    `client_ip` VARCHAR(40) NOT NULL DEFAULT ''
);