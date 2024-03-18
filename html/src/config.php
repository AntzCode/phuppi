<?php

$fuppiConfig = [
    'fuppi_version' => '1.0.3r1',
    'sqlite3FilePath' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'FUPPI_DB.sqlite3',
    'uploadedFilesPath' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uploadedFiles',
    'post_max_size' => ini_get('post_max_size')
];
