CREATE TABLE `fuppi_voucher_permissions` (
    `voucher_permission_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `voucher_id` INTEGER NOT NULL,
    `permission_name` VARCHAR(255) NOT NULL,
    `permission_value` VARCHAR(255) NOT NULL,
    FOREIGN KEY (voucher_id) REFERENCES fuppi_vouchers (voucher_id) 
);