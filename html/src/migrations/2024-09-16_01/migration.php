<?php
/**
 * v1.1.1 migration 1 : new feature: support for Digital Ocean Spaces.
 */

$searchStatement = $pdo->prepare("SELECT `value` FROM `fuppi_settings` WHERE `name` = 'use_aws_s3'");

if ($searchResult = $searchStatement->execute()) {
    foreach ($searchStatement->fetchAll(PDO::FETCH_ASSOC) as $settingValue) {
        if (!!$settingValue['value']) {
            echo 'Configured to use AWS S3...' . PHP_EOL;
            $deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'file_storage_type'");
            $deleteStatement->execute();

            $insertStatement = $pdo->prepare("INSERT INTO `fuppi_settings` (`name`, `value`) VALUES ('file_storage_type', 'aws_s3')");
            $insertStatement->execute();

            $deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'use_aws_s3'");
            $deleteStatement->execute();
        } else {
            echo 'Configured to not use AWS S3...' . PHP_EOL;
            $deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'file_storage_type'");
            $deleteStatement->execute();

            $insertStatement = $pdo->prepare("INSERT INTO `fuppi_settings` (`name`, `value`) VALUES ('file_storage_type', 'server_filesystem')");
            $insertStatement->execute();

            $deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'use_aws_s3'");
            $deleteStatement->execute();
        }
    }
} else {
    echo "could not resolve the previous setting, so will continue without applying any upgrades";
}

echo 'finished upgrading settings format' . PHP_EOL;
