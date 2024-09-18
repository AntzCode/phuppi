<?php
/**
 * v1.1.1 migration 2 : new feature: support for Digital Ocean Spaces.
 */

echo "upgrade settings name from `aws_s3_bucket` to `remote_files_container`..." . PHP_EOL;
$insertStatement = $pdo->prepare("INSERT INTO `fuppi_settings` (`name`, `value`) VALUES ('remote_files_container', (SELECT `value` FROM `fuppi_settings` WHERE `name` = 'aws_s3_bucket'))");
$insertStatement->execute();

$deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'aws_s3_bucket'");
$deleteStatement->execute();


echo "upgrade settings name from `aws_s3_region` to `remote_files_region`..." . PHP_EOL;
$insertStatement = $pdo->prepare("INSERT INTO `fuppi_settings` (`name`, `value`) VALUES ('remote_files_region', (SELECT `value` FROM `fuppi_settings` WHERE `name` = 'aws_s3_region'))");
$insertStatement->execute();

$deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'aws_s3_region'");
$deleteStatement->execute();

echo "upgrade settings name from `aws_s3_access_key` to `remote_files_access_key`..." . PHP_EOL;
$insertStatement = $pdo->prepare("INSERT INTO `fuppi_settings` (`name`, `value`) VALUES ('remote_files_access_key', (SELECT `value` FROM `fuppi_settings` WHERE `name` = 'aws_s3_access_key'))");
$insertStatement->execute();

$deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'aws_s3_access_key'");
$deleteStatement->execute();


echo "upgrade settings name from `aws_s3_secret` to `remote_files_secret`..." . PHP_EOL;
$insertStatement = $pdo->prepare("INSERT INTO `fuppi_settings` (`name`, `value`) VALUES ('remote_files_secret', (SELECT `value` FROM `fuppi_settings` WHERE `name` = 'aws_s3_secret'))");
$insertStatement->execute();

$deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'aws_s3_secret'");
$deleteStatement->execute();


echo "upgrade settings name from `aws_token_lifetime_seconds` to `remote_files_token_lifetime_seconds`..." . PHP_EOL;
$insertStatement = $pdo->prepare("INSERT INTO `fuppi_settings` (`name`, `value`) VALUES ('remote_files_token_lifetime_seconds', (SELECT `value` FROM `fuppi_settings` WHERE `name` = 'aws_token_lifetime_seconds'))");
$insertStatement->execute();

$deleteStatement = $pdo->prepare("DELETE FROM `fuppi_settings` WHERE `name` = 'aws_token_lifetime_seconds'");
$deleteStatement->execute();

echo "attempt generate remote files endpoint:" . PHP_EOL;
$searchStatement = $pdo->prepare("SELECT `name`, `value` FROM `fuppi_settings`");

$settings = [];

if ($searchResult = $searchStatement->execute()) {
    foreach ($searchStatement->fetchAll(PDO::FETCH_ASSOC) as $settingsRow) {
        $settings[$settingsRow['name']] = $settingsRow['value'];
    }
}

if (empty($settings['remote_files_region'])) {
    echo "Region \"{$settings['remote_files_region']}\" is a required component of an endpoint, cannot generate an endpoint." . PHP_EOL;
} else {
    $endpoint = "https://s3.{$settings['remote_files_region']}.amazonaws.com";
    echo "Generated endpoint: \"{$endpoint}\"" . PHP_EOL;

    $insertStatement = $pdo->prepare("INSERT INTO `fuppi_settings` (`name`, `value`) VALUES ('remote_files_endpoint', :endpoint)");
    $insertStatement->execute(['endpoint' => $endpoint]);
}

echo 'finished upgrading settings format' . PHP_EOL;
