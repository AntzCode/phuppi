<?php
/**
 * v1.0.7 migration 1 : new feature: Download multiple files.
 */

$dbSchemaDirectoryName = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'databaseSchema';

// create the database table for notes
$filename = $dbSchemaDirectoryName . DIRECTORY_SEPARATOR . 'temporary_files.sql';
echo 'create database table `temporary_files` (' . $filename . ')' . PHP_EOL;
$query = file_get_contents($filename);
$pdo->query($query);

if ($config->getSetting('aws_token_lifetime_seconds') === null) {
    $config->setSetting('aws_token_lifetime_seconds', 86400);
}
