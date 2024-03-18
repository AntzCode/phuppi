CREATE TABLE `fuppi_vouchers` (
    `voucher_id`INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `voucher_code` VARCHAR(255) NOT NULL,
    `session_id` VARCHAR(255) NOT NULL,
    `created_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATE NULL DEFAULT NULL,
    `redeemed_at` DATE NULL DEFAULT NULL,
    `deleted_at` DATE NULL DEFAULT NULL,
    `valid_for` INT NULL,
    `notes` TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (user_id) REFERENCES fuppi_users (user_id)
);