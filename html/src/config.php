<?php

$fuppiConfig = [
    'fuppi_version' => '1.0.4',
    'sqlite3FilePath' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'FUPPI_DB.sqlite3',
    'uploadedFilesPath' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uploadedFiles',
    'post_max_size' => ini_get('post_max_size'),
    'voucher_valid_for_options' => [
        300 => "5 mins", 600 => "10 mins", 1800 => "30 mins", 3600 => "1 hr", 7200 => "2 hrs", 14400 => "4 hrs", 43200 => "12 hrs", 86400 => "1 day", 259200 => "3 days", 604800 => "1 wk", 2678400 => "1 mth", 7884000 => "3 mths", 15768000 => "6 mths", 31536000 => "1 yr", 0 => "Permanent"
    ],
    'token_valid_for_options' => [
        300 => "5 mins", 600 => "10 mins", 1800 => "30 mins", 3600 => "1 hr", 7200 => "2 hrs", 14400 => "4 hrs", 43200 => "12 hrs", 86400 => "1 day", 259200 => "3 days", 604800 => "1 wk", 2678400 => "1 mth", 7884000 => "3 mths"
    ],
];
