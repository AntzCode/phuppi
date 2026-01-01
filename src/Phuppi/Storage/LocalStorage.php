<?php

namespace Phuppi\Storage;

use Flight;

class LocalStorage implements StorageInterface
{
    private string $basePath;
    private string $pathPrefix;

    public function __construct(array $config = [])
    {
        $this->basePath = $config['path'] ?? Flight::get('flight.data.path') . DIRECTORY_SEPARATOR . 'uploads';
        $this->pathPrefix = $config['path_prefix'] ?? '';
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    public function getRelativePath(string $filename): string
    {
        return $this->pathPrefix ? $this->pathPrefix . '/' . $filename : $filename;
    }

    public function put(string $filename, string $sourcePath): bool
    {
        $targetPath = $this->getFullPath($filename);
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return move_uploaded_file($sourcePath, $targetPath) || copy($sourcePath, $targetPath);
    }

    public function getStream(string $filename)
    {
        $path = $this->getFullPath($filename);
        if (file_exists($path)) {
            return fopen($path, 'rb');
        }
        return null;
    }

    public function exists(string $filename): bool
    {
        $path = $this->getFullPath($filename);
        return file_exists($path);
    }

    public function delete(string $filename): bool
    {
        $path = $this->getFullPath($filename);
        if (file_exists($path)) {
            return unlink($path);
        }
        return true; // Already deleted
    }

    public function size(string $filename): ?int
    {
        $path = $this->getFullPath($filename);
        if (file_exists($path)) {
            return filesize($path);
        }
        return null;
    }

    public function getUrl(string $filename): ?string
    {
        // For local storage, return null as there's no public URL
        // The app uses token-based access
        return null;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getPathPrefix(): string
    {
        return $this->pathPrefix;
    }

    private function getFullPath(string $filename): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->getRelativePath($filename));
    }
}