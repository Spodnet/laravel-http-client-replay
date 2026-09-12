<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Repositories;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Manager;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Enums\StorageDriver;

class CassetteRepositoryManager extends Manager implements CassetteRepositoryInterface
{
    public function getDefaultDriver(): string
    {
        /** @var StorageDriver|string $driver */
        $driver = $this->config->get('http-client-replay.driver', StorageDriver::File->value);

        return $driver instanceof StorageDriver ? $driver->value : (string) $driver;
    }

    public function createFileDriver(): CassetteRepositoryInterface
    {
        /** @var string $path */
        $path = $this->config->get(
            'http-client-replay.drivers.file.path',
            storage_path('http-client-replay/cassettes'),
        );

        return new JsonFileCassetteRepository($path);
    }

    public function createDiskDriver(): CassetteRepositoryInterface
    {
        /** @var FilesystemFactory $storage */
        $storage = $this->container->make('filesystem');
        /** @var string $diskName */
        $diskName = $this->config->get('http-client-replay.drivers.disk.disk', 'local');
        /** @var string $path */
        $path = $this->config->get('http-client-replay.drivers.disk.path', 'http-client-replay/cassettes');

        return new StorageDiskCassetteRepository(
            $storage->disk($diskName),
            $path,
        );
    }

    public function createDatabaseDriver(): CassetteRepositoryInterface
    {
        /** @var DatabaseManager $db */
        $db = $this->container->make('db');
        /** @var string|null $connection */
        $connection = $this->config->get('http-client-replay.drivers.database.connection');
        /** @var string $table */
        $table = $this->config->get('http-client-replay.drivers.database.table', 'http_client_replay_cassettes');

        return new DatabaseCassetteRepository(
            $db->connection($connection),
            $table,
        );
    }

    public function has(string $identifier): bool
    {
        /** @var CassetteRepositoryInterface $driver */
        $driver = $this->driver();

        return $driver->has($identifier);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $identifier): ?array
    {
        /** @var CassetteRepositoryInterface $driver */
        $driver = $this->driver();

        return $driver->find($identifier);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(string $identifier, array $data): void
    {
        /** @var CassetteRepositoryInterface $driver */
        $driver = $this->driver();

        $driver->store($identifier, $data);
    }

    public function delete(string $identifier): bool
    {
        /** @var CassetteRepositoryInterface $driver */
        $driver = $this->driver();

        return $driver->delete($identifier);
    }

    /**
     * @return array<int, array{identifier: string, path: string, size: int, updated_at: int, signature: string, status: int}>
     */
    public function all(): array
    {
        /** @var CassetteRepositoryInterface $driver */
        $driver = $this->driver();

        return $driver->all();
    }

    public function clear(?string $domain = null): int
    {
        /** @var CassetteRepositoryInterface $driver */
        $driver = $this->driver();

        return $driver->clear($domain);
    }
}
