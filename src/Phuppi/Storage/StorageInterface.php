<?php

/**
 * StorageInterface.php
 *
 * StorageInterface class for managing file storage in the Phuppi application.
 *
 * @package Phuppi\Storage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Storage;

interface StorageInterface
{
    /**
     * Store a file from a local path (e.g., uploaded temp file)
     * 
     * @param string $filename The filename to store as
     * @param string $sourcePath The local path of the file to store
     * @return bool Success
     */
    public function put(string $filename, string $sourcePath): bool;

    /**
     * Get the file content as a stream resource
     * 
     * @param string $filename
     * @return resource|null File handle or null if not found
     */
    public function getStream(string $filename);

    /**
     * Check if file exists
     * 
     * @param string $filename
     * @return bool
     */
    public function exists(string $filename): bool;

    /**
     * Delete a file
     * 
     * @param string $filename
     * @return bool Success
     */
    public function delete(string $filename): bool;

    /**
     * Get file size
     * 
     * @param string $filename
     * @return int|null Size in bytes or null if not found
     */
    public function size(string $filename): ?int;

    /**
     * Get a public URL for the file (if supported)
     * 
     * @param string $filename
     * @return string|null URL or null if not supported
     */
    public function getUrl(string $filename): ?string;

    /**
     * Get a relative path for the file
     * 
     * @param string $filename
     * @return string|null Relative path
     */
    public function getRelativePath(string $filename): string;
}
