CREATE TABLE `fuppi_notes` (
    `note_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `voucher_id` INTEGER NULL,
    `filename` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `created_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES fuppi_users (user_id),
    FOREIGN KEY (voucher_id) REFERENCES fuppi_vouchers (voucher_id)
);