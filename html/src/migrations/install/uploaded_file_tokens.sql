CREATE TABLE `fuppi_uploaded_file_tokens` (
    `uploaded_file_token_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `uploaded_file_id` INTEGER NOT NULL,
    `voucher_id` INTEGER NULL,
    `token` VARCHAR(255) NOT NULL,
    `created_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_file_id) REFERENCES fuppi_uploaded_files (uploaded_file_id),
    FOREIGN KEY (voucher_id) REFERENCES fuppi_vouchers (voucher_id)
);