CREATE TABLE `fuppi_note_tokens` (
    `note_token_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `note_id` INTEGER NOT NULL,
    `voucher_id` INTEGER NULL,
    `token` VARCHAR(255) NOT NULL,
    `created_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES fuppi_notes (note_id),
    FOREIGN KEY (voucher_id) REFERENCES fuppi_vouchers (voucher_id)
);