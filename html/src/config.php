<?php

$fuppiConfig = [
    'fuppi_version' => '1.1.1',
    'base_url' => $_SERVER['SERVER_NAME'],
    'phpliteadmin_folder_name' => 'phpliteadmin',
    'sqlite3_file_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'FUPPI_DB.sqlite3',
    'uploaded_files_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uploadedFiles',
    'remote_uploaded_files_prefix' => 'data/uploadedFiles',
    'voucher_valid_for_options' => [
        300 => "5 mins", 600 => "10 mins", 1800 => "30 mins", 3600 => "1 hr", 7200 => "2 hrs", 14400 => "4 hrs", 43200 => "12 hrs", 86400 => "1 day", 259200 => "3 days", 604800 => "1 wk", 2678400 => "1 mth", 7884000 => "3 mths", 15768000 => "6 mths", 31536000 => "1 yr", 0 => "Permanent"
    ],
    'token_valid_for_options' => [
        300 => "5 mins", 600 => "10 mins", 1800 => "30 mins", 3600 => "1 hr", 7200 => "2 hrs", 14400 => "4 hrs", 43200 => "12 hrs", 86400 => "1 day", 259200 => "3 days", 604800 => "1 wk", 2678400 => "1 mth", 7884000 => "3 mths"
    ],
    'settings' => [
        [
            "title" => "File Storage Type",
            "name" => "file_storage_type",
            "value" => 0,
            "type" => "option",
            "options" => [
                "server_filesystem" => "Server Filesystem",
                "aws_s3" => "AWS S3",
                "do_spaces" => "Digital Ocean Spaces"
            ]],
        [
            "title" => "Remote Files Region",
            "name" => "remote_files_region",
            "value" => "",
            "type" => "string",
            "show_if" => [
                "file_storage_type" => ["aws_s3", "do_spaces"]
            ]
        ],
        [
            "title" => "Remote Files Endpoint",
            "name" => "remote_files_endpoint",
            "value" => "",
            "type" => "string",
            "show_if" => [
                "file_storage_type" => ["aws_s3", "do_spaces"]
            ]
        ],
        [
            "title" => "Remote Files Container",
            "name" => "remote_files_container",
            "value" => "",
            "type" => "string",
            "show_if" => [
                "file_storage_type" => ["aws_s3", "do_spaces"]
            ]
        ],
        [
            "title" => "Remote Files Access Key",
            "name" => "remote_files_access_key",
            "value" => "",
            "type" => "string",
            "show_if" => [
                "file_storage_type" => ["aws_s3", "do_spaces"]
            ]
        ],
        [
            "title" => "Remote Files Secret",
            "name" => "remote_files_secret",
            "value" => "",
            "type" => "password",
            "show_if" => [
                "file_storage_type" => ["aws_s3", "do_spaces"]
            ]
        ],
        [
            "title" => "Remote Files Token Lifetime (Seconds)",
            "name" => "remote_files_token_lifetime_seconds",
            "value" => "86400",
            "type" => "string",
            "show_if" => [
                "file_storage_type" => ["aws_s3", "do_spaces"]
            ]
        ],
        [
            "title" => "AWS Lambda Multiple-Download Zip Function Name",
            "name" => "aws_lambda_multiple_zip_function_name",
            "value" => "",
            "type" => "string",
            "show_if" => [
                "file_storage_type" => "aws_s3"
            ]
        ],
    ],
    'image_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
    ],
    'video_mime_types' => [
        'video/mp4'
    ]
];
