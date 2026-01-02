<?php

/**
 * LocalStorage.php
 *
 * LocalStorage class for managing file storage locally in the Phuppi application.
 *
 * @package Phuppi\Storage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Storage;

use Flight;

class LocalStorage implements StorageInterface
{
    /** @var string */
    private string $basePath;

    /** @var string */
    private string $pathPrefix;

    /**
     * Constructor for LocalStorage.
     *
     * @param array $config Configuration array with path and path_prefix.
     */
    public function __construct(array $config = [])
    {
        $this->basePath = $config['path'] ?? Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'uploads';
        $this->pathPrefix = $config['path_prefix'] ?? '';
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * Gets the relative path for a filename.
     *
     * @param string $filename The filename.
     * @return string The relative path.
     */
    public function getRelativePath(string $filename): string
    {
        return $this->pathPrefix ? $this->pathPrefix . '/' . $filename : $filename;
    }

    /**
     * Uploads a file to local storage.
     *
     * @param string $filename The filename.
     * @param string $sourcePath The source file path.
     * @return bool True if uploaded successfully, false otherwise.
     */
    public function put(string $filename, string $sourcePath): bool
    {
        $targetPath = $this->getFullPath($filename);
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return move_uploaded_file($sourcePath, $targetPath) || copy($sourcePath, $targetPath);
    }

    /**
     * Gets a stream for the file.
     *
     * @param string $filename The filename.
     * @return resource|null The stream or null if not found.
     */
    public function getStream(string $filename)
    {
        $path = $this->getFullPath($filename);
        if (file_exists($path)) {
            return fopen($path, 'rb');
        }
        return null;
    }

    /**
     * Checks if a file exists.
     *
     * @param string $filename The filename.
     * @return bool True if exists, false otherwise.
     */
    public function exists(string $filename): bool
    {
        $path = $this->getFullPath($filename);
        return file_exists($path);
    }

    /**
     * Deletes a file from local storage.
     *
     * @param string $filename The filename.
     * @return bool True if deleted successfully, false otherwise.
     */
    public function delete(string $filename): bool
    {
        $path = $this->getFullPath($filename);
        if (file_exists($path)) {
            if (unlink($path)) {
                Flight::logger()->info('LocalStorage delete: Successfully deleted ' . $path);
                return true;
            } else {
                Flight::logger()->error('LocalStorage delete: Failed to delete ' . $path);
                return false;
            }
        }
        Flight::logger()->info('LocalStorage delete: File not found ' . $path);
        return true; // Already deleted
    }

    /**
     * Gets the size of a file.
     *
     * @param string $filename The filename.
     * @return ?int The size in bytes or null if not found.
     */
    public function size(string $filename): ?int
    {
        $path = $this->getFullPath($filename);
        if (file_exists($path)) {
            return filesize($path);
        }
        return null;
    }

    /**
     * Gets a URL for the file (not applicable for local storage).
     *
     * @param string $filename The filename.
     * @return ?string Always returns null.
     */
    public function getUrl(string $filename): ?string
    {
        // For local storage, return null as there's no public URL
        // The app uses token-based access
        return null;
    }

    /**
     * Gets the base path.
     *
     * @return string The base path.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Gets the path prefix.
     *
     * @return string The path prefix.
     */
    public function getPathPrefix(): string
    {
        return $this->pathPrefix;
    }

    /**
     * Gets the full path for a filename.
     *
     * @param string $filename The filename.
     * @return string The full path.
     */
    private function getFullPath(string $filename): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->getRelativePath($filename));
    }
}
