CREATE TABLE `fuppi_uploaded_files_remote_auth` (
    `fuppi_uploaded_files_remote_auth_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `uploaded_file_id` INTEGER NOT NULL,
    `voucher_id` INTEGER NULL,
    `action` VARCHAR(10) NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `expires_at` DATE NOT NULL,
    FOREIGN KEY (uploaded_file_id) REFERENCES fuppi_uploaded_files (uploaded_file_id),
    FOREIGN KEY (voucher_id) REFERENCES fuppi_vouchers (voucher_id)
);

