CREATE TABLE `fuppi_user_permissions` (
    `user_permission_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `permission_name` VARCHAR(255) NOT NULL,
    `permission_value` VARCHAR(255) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES fuppi_users (user_id) 
);