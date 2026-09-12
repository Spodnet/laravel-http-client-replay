<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Repositories;

use Illuminate\Contracts\Filesystem\Filesystem;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;

class StorageDiskCassetteRepository implements CassetteRepositoryInterface
{
    public function __construct(
        protected Filesystem $filesystem,
        protected string $basePath = 'http-client-replay/cassettes',
    ) {
        $this->basePath = trim(str_replace('\\', '/', $basePath), '/');
    }

    public function has(string $identifier): bool
    {
        return $this->filesystem->exists($this->filePath($identifier));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $identifier): ?array
    {
        $path = $this->filePath($identifier);

        if (! $this->filesystem->exists($path)) {
            return null;
        }

        $content = $this->filesystem->get($path);

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
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->filesystem->put($path, (string) $json);
    }

    public function delete(string $identifier): bool
    {
        $path = $this->filePath($identifier);

        if ($this->filesystem->exists($path)) {
            return $this->filesystem->delete($path);
        }

        return false;
    }

    /**
     * @return array<int, array{identifier: string, path: string, size: int, updated_at: int, signature: string, status: int}>
     */
    public function all(): array
    {
        $files = $this->filesystem->allFiles($this->basePath);
        $cassettes = [];

        foreach ($files as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }

            $normalizedFile = str_replace('\\', '/', $file);
            $relativePath = substr($normalizedFile, strlen($this->basePath) + 1);
            $identifier = str_replace('\\', '/', preg_replace('/\.json$/', '', $relativePath) ?: $relativePath);

            $content = $this->filesystem->get($file);
            $signature = 'Unknown';
            $status = 200;

            /** @var array<string, mixed>|null $data */
            $data = json_decode($content, true);

            if (is_array($data)) {
                $signature = (string) ($data['signature'] ?? ($data['request']['method'] ?? '').' '.($data['request']['url'] ?? ''));
                $status = (int) ($data['response']['status'] ?? 200);
            }

            $cassettes[] = [
                'identifier' => $identifier,
                'path' => $normalizedFile,
                'size' => $this->filesystem->size($file),
                'updated_at' => $this->filesystem->lastModified($file),
                'signature' => trim($signature),
                'status' => $status,
            ];
        }

        usort($cassettes, fn (array $a, array $b): int => strcmp($a['identifier'], $b['identifier']));

        return $cassettes;
    }

    public function clear(?string $domain = null): int
    {
        $count = 0;

        if ($domain !== null && $domain !== '') {
            $domainClean = trim(str_replace('\\', '/', $domain), '/');
            $directory = $this->basePath.'/'.$domainClean;
            $files = $this->filesystem->allFiles($directory);

            foreach ($files as $file) {
                if ($this->filesystem->delete($file)) {
                    $count++;
                }
            }

            $this->filesystem->deleteDirectory($directory);
        } else {
            $files = $this->filesystem->allFiles($this->basePath);

            foreach ($files as $file) {
                if ($this->filesystem->delete($file)) {
                    $count++;
                }
            }

            $this->filesystem->deleteDirectory($this->basePath);
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
}
