<?php
/**
 * v1.0.8 migration 1 : new feature: Rename files.
 */

$dbSchemaDirectoryName = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'databaseSchema';

// add the columns for display_filename and notes

echo 'rename table `fuppi_uploaded_files` to `fuppi_uploaded_files_bup`' . PHP_EOL;
$query = "ALTER TABLE `fuppi_uploaded_files` RENAME TO `fuppi_uploaded_files_bup`";
$pdo->query($query);

$filename = $dbSchemaDirectoryName . DIRECTORY_SEPARATOR . 'uploaded_files.sql';
echo 'create a new table from latest schema (' . $filename . ')' . PHP_EOL;
$query = file_get_contents($filename);
$pdo->query($query);

echo 'copy data from `fuppi_uploaded_files_bup` into new table `fuppi_uploaded_files`' . PHP_EOL;
$colnames = [
    '`uploaded_file_id`',
    '`user_id`',
    '`voucher_id`',
    '`filename`',
    '`display_filename`',
    '`filesize`',
    '`mimetype`',
    '`extension`',
    '`uploaded_at`',
    '`notes`'
];
$colnamesMap = $colnames;
$colnamesMap[array_search('`display_filename`', $colnames)] = '`filename`';
$colnamesMap[array_search('`notes`', $colnames)] = 'substr(`filename`, 0, 0)';

$query = "INSERT INTO fuppi_uploaded_files(" . implode(', ', $colnames) . ") SELECT " . implode(', ', $colnamesMap) . " FROM `fuppi_uploaded_files_bup`";
$pdo->query($query);

echo 'delete temporary backup table `fuppi_uploaded_files_bup`' . PHP_EOL;
$query = "DROP TABLE `fuppi_uploaded_files_bup`";
$pdo->query($query);

if ($config->getSetting('remote_files_token_lifetime_seconds') === null) {
    $config->setSetting('remote_files_token_lifetime_seconds', 86400);
}
