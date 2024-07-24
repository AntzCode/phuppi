<?php

$fuppiConfig = [
    'fuppi_version' => '1.0.13',
    'phpliteadmin_folder_name' => 'phpliteadmin',
    'sqlite3_file_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'FUPPI_DB.sqlite3',
    'uploaded_files_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uploadedFiles',
    's3_uploaded_files_prefix' => 'data/uploadedFiles',
    'voucher_valid_for_options' => [
        300 => "5 mins", 600 => "10 mins", 1800 => "30 mins", 3600 => "1 hr", 7200 => "2 hrs", 14400 => "4 hrs", 43200 => "12 hrs", 86400 => "1 day", 259200 => "3 days", 604800 => "1 wk", 2678400 => "1 mth", 7884000 => "3 mths", 15768000 => "6 mths", 31536000 => "1 yr", 0 => "Permanent"
    ],
    'token_valid_for_options' => [
        300 => "5 mins", 600 => "10 mins", 1800 => "30 mins", 3600 => "1 hr", 7200 => "2 hrs", 14400 => "4 hrs", 43200 => "12 hrs", 86400 => "1 day", 259200 => "3 days", 604800 => "1 wk", 2678400 => "1 mth", 7884000 => "3 mths"
    ],
    'settings' => [
        ["name" => "use_aws_s3", "value" => 0, "type" => "boolean"],
        ["name" => "aws_s3_region", "value" => "", "type" => "string"],
        ["name" => "aws_s3_bucket", "value" => "", "type" => "string"],
        ["name" => "aws_s3_access_key", "value" => "", "type" => "string"],
        ["name" => "aws_s3_secret", "value" => "", "type" => "password"],
        ["name" => "aws_token_lifetime_seconds", "value" => "86400", "type" => "string"],
        ["name" => "aws_lambda_multiple_zip_function_name", "value" => "", "type" => "string"],
    ]
];
