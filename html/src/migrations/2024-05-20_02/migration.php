<?php
/**
 * v1.0.6 migration 1 : new feature: Notes.
 */

$installDirectoryName = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'install';


// create the database table for notes
$filename = $installDirectoryName . DIRECTORY_SEPARATOR . 'notes.sql';
echo 'create database table `notes` (' . $filename . ')' . PHP_EOL;
$query = file_get_contents($installDirectoryName . DIRECTORY_SEPARATOR . 'notes.sql');
$pdo->query($query);

// create the database table for note_tokens
$filename = $installDirectoryName . DIRECTORY_SEPARATOR . 'note_tokens.sql';
echo 'create database table `note_tokens` (' . $filename . ')' . PHP_EOL;
$query = file_get_contents($installDirectoryName . DIRECTORY_SEPARATOR . 'note_tokens.sql');
$pdo->query($query);
