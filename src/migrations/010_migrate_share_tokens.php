<?php

/**
 * Migration: Shorten existing share token expiry dates
 * 
 * This migration shortens all existing share tokens to expire by 2027-03-20
 * to align with the deprecation schedule for old share URL formats.
 * 
 * After this date, the following deprecated routes will be removed:
 * - GET /files/batch/{token}
 * - GET /files/{id}?token={token} (for UploadedFileToken)
 * 
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.5.0
 * @deprecated since 2.5.0, will be removed on 2027-03-20
 * @TODO: Remove this migration after 2027-03-20 when all existing share tokens have expired.
 */

$db = Flight::db();

// Cutoff date for deprecated routes (1 year from now)
$cutoffDate = '2027-03-20 00:00:00';

// Migrate uploaded_file_tokens table
$stmt = $db->prepare("
    UPDATE uploaded_file_tokens 
    SET expires_at = ?
    WHERE expires_at IS NOT NULL 
    AND expires_at > ?
");
$stmt->execute([$cutoffDate, $cutoffDate]);
$uploadedFileTokensUpdated = $stmt->rowCount();

// Migrate batch_file_tokens table
$stmt = $db->prepare("
    UPDATE batch_file_tokens 
    SET expires_at = ?
    WHERE expires_at IS NOT NULL 
    AND expires_at > ?
");
$stmt->execute([$cutoffDate, $cutoffDate]);
$batchFileTokensUpdated = $stmt->rowCount();

Flight::logger()->info("Migration 010: Shortened {$uploadedFileTokensUpdated} uploaded_file_tokens and {$batchFileTokensUpdated} batch_file_tokens to expire by {$cutoffDate}");
