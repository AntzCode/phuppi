CREATE TABLE `fuppi_temporary_files` (
    `temporary_file_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `voucher_id` INTEGER NULL,
    `filename` VARCHAR(255) NOT NULL,
    `filesize` INTEGER NOT NULL,
    `mimetype` VARCHAR(255),
    `extension` VARCHAR(8),
    `created_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES fuppi_users (user_id),
    FOREIGN KEY (voucher_id) REFERENCES fuppi_vouchers (voucher_id)
);