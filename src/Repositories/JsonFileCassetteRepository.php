<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Repositories;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;

class JsonFileCassetteRepository implements CassetteRepositoryInterface
{
    public function __construct(
        protected string $basePath,
    ) {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
    }

    public function has(string $identifier): bool
    {
        return file_exists($this->filePath($identifier));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $identifier): ?array
    {
        $path = $this->filePath($identifier);

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(string $identifier, array $data): void
    {
        $path = $this->filePath($identifier);
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($path, $json);
    }

    public function delete(string $identifier): bool
    {
        $path = $this->filePath($identifier);

        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    /**
     * @return array<int, array{identifier: string, path: string, size: int, updated_at: int, signature: string, status: int}>
     */
    public function all(): array
    {
        if (! is_dir($this->basePath)) {
            return [];
        }

        $cassettes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $rawPath = $file->getPathname();
            $fullPath = str_replace('\\', '/', $rawPath);

            if ($file->isFile() && $file->getExtension() === 'json' && file_exists($rawPath)) {
                $relativePath = substr($fullPath, strlen($this->basePath) + 1);
                $identifier = str_replace('\\', '/', preg_replace('/\.json$/', '', $relativePath) ?: $relativePath);

                $content = @file_get_contents($rawPath);
                $signature = 'Unknown';
                $status = 200;

                if ($content !== false) {
                    /** @var array<string, mixed>|null $data */
                    $data = json_decode($content, true);

                    if (is_array($data)) {
                        $signature = (string) ($data['signature'] ?? ($data['request']['method'] ?? '').' '.($data['request']['url'] ?? ''));
                        $status = (int) ($data['response']['status'] ?? 200);
                    }
                }

                $size = @filesize($rawPath);
                $mtime = @filemtime($rawPath);

                $cassettes[] = [
                    'identifier' => $identifier,
                    'path' => $fullPath,
                    'size' => $size !== false ? $size : 0,
                    'updated_at' => $mtime !== false ? $mtime : 0,
                    'signature' => trim($signature),
                    'status' => $status,
                ];
            }
        }

        usort($cassettes, fn (array $a, array $b): int => strcmp($a['identifier'], $b['identifier']));

        return $cassettes;
    }

    public function clear(?string $domain = null): int
    {
        if (! is_dir($this->basePath)) {
            return 0;
        }

        $count = 0;

        if ($domain !== null && $domain !== '') {
            $domainClean = trim(str_replace('\\', '/', $domain), '/');
            $domainPath = $this->basePath.'/'.$domainClean;

            if (is_dir($domainPath)) {
                $count = $this->deleteDirectory($domainPath);
            }
        } else {
            $count = $this->deleteDirectoryContents($this->basePath);
        }

        return $count;
    }

    protected function filePath(string $identifier): string
    {
        $normalized = ltrim(str_replace(['..', '\\'], ['', '/'], $identifier), '/');

        if (! str_ends_with($normalized, '.json')) {
            $normalized .= '.json';
        }

        return $this->basePath.'/'.$normalized;
    }

    protected function deleteDirectory(string $dir): int
    {
        $count = 0;
        $items = scandir($dir);

        if ($items === false) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path)) {
                $count += $this->deleteDirectory($path);
            } else {
                if (unlink($path)) {
                    $count++;
                }
            }
        }

        @rmdir($dir);

        return $count;
    }

    protected function deleteDirectoryContents(string $dir): int
    {
        $count = 0;
        $items = scandir($dir);

        if ($items === false) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path)) {
                $count += $this->deleteDirectory($path);
            } else {
                if (unlink($path)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
