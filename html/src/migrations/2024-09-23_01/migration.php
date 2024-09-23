<?php
/**
 * v1.1.8 migration 1 : new feature: persistent login.
 */

$dbSchemaDirectoryName = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'databaseSchema';

$filename = $dbSchemaDirectoryName . DIRECTORY_SEPARATOR . 'user_sessions.sql';
echo 'create a new table from latest schema (' . $filename . ')' . PHP_EOL;
$query = file_get_contents($filename);
$pdo->query($query);
