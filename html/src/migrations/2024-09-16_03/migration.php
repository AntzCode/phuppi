<?php
/**
 * v1.1.1 migration 3 : new feature: support for Digital Ocean Spaces.
 */

echo "procedure to rename database table name from `fuppi_uploaded_files_aws_auth` to `fuppi_uploaded_files_remote_auth`:" . PHP_EOL;

echo "create a new table with name `fuppi_uploaded_files_remote_auth`..." . PHP_EOL;

$sql = "CREATE TABLE `fuppi_uploaded_files_remote_auth` (
    `fuppi_uploaded_files_remote_auth_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `uploaded_file_id` INTEGER NOT NULL,
    `voucher_id` INTEGER NULL,
    `action` VARCHAR(10) NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `expires_at` DATE NOT NULL,
    FOREIGN KEY (uploaded_file_id) REFERENCES fuppi_uploaded_files (uploaded_file_id),
    FOREIGN KEY (voucher_id) REFERENCES fuppi_vouchers (voucher_id)
)";

$createStatement = $pdo->prepare($sql);
$createStatement->execute();

echo "copy existing data from old table `fuppi_uploaded_files_aws_auth` to new table `fuppi_uploaded_files_remote_auth`..." . PHP_EOL;

$sql = "INSERT INTO `fuppi_uploaded_files_remote_auth` (
    `fuppi_uploaded_files_remote_auth_id`,
    `uploaded_file_id`,
    `voucher_id`,
    `action`,
    `url`,
    `expires_at`
) SELECT `fuppi_uploaded_files_aws_auth_id`,
    `uploaded_file_id`,
    `voucher_id`,
    `action`,
    `url`,
    `expires_at`
FROM `fuppi_uploaded_files_aws_auth`";

$insertStatement = $pdo->prepare($sql);
$insertStatement->execute();

echo "drop the old table `fuppi_uploaded_files_aws_auth`..." . PHP_EOL;

$dropStatement = $pdo->prepare("DROP TABLE `fuppi_uploaded_files_aws_auth`");
$dropStatement->execute();

echo 'finished renaming database table' . PHP_EOL;
