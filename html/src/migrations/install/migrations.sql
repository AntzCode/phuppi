CREATE TABLE `fuppi_migrations` (
    `migration_id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `filename` VARCHAR(20) NOT NULL DEFAULT '',
    `date` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP
);