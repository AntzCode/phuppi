<?php
/**
 * v1.2.0 migration 1 : new feature: tags for uploaded files.
 */

$dbSchemaDirectoryName = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'databaseSchema';

$filename = $dbSchemaDirectoryName . DIRECTORY_SEPARATOR . 'tags.sql';
echo 'create a new table from latest schema (' . $filename . ')' . PHP_EOL;
$query = file_get_contents($filename);
$pdo->query($query);

$filename = $dbSchemaDirectoryName . DIRECTORY_SEPARATOR . 'uploaded_files_tags.sql';
echo 'create a new table from latest schema (' . $filename . ')' . PHP_EOL;
$query = file_get_contents($filename);
$pdo->query($query);
