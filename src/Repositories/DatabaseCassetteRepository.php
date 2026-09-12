<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Repositories;

use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;

class DatabaseCassetteRepository implements CassetteRepositoryInterface
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table = 'http_client_replay_cassettes',
    ) {}

    public function has(string $identifier): bool
    {
        return $this->connection->table($this->table)
            ->where('identifier', $identifier)
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $identifier): ?array
    {
        /** @var object{data: string}|null $record */
        $record = $this->connection->table($this->table)
            ->where('identifier', $identifier)
            ->first(['data']);

        if (! $record) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($record->data, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(string $identifier, array $data): void
    {
        $signature = (string) ($data['signature'] ?? ($data['request']['method'] ?? '').' '.($data['request']['url'] ?? ''));
        $status = (int) ($data['response']['status'] ?? 200);

        $parts = explode('/', $identifier, 2);
        $domain = count($parts) > 1 ? $parts[0] : null;

        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $now = now();

        $this->connection->table($this->table)->updateOrInsert(
            ['identifier' => $identifier],
            [
                'domain' => $domain,
                'signature' => $signature,
                'status' => $status,
                'data' => (string) $json,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function delete(string $identifier): bool
    {
        return $this->connection->table($this->table)
            ->where('identifier', $identifier)
            ->delete() > 0;
    }

    /**
     * @return array<int, array{identifier: string, path: string, size: int, updated_at: int, signature: string, status: int}>
     */
    public function all(): array
    {
        /** @var Collection<int, object{identifier: string, data: string, updated_at: string|DateTimeInterface, signature: string, status: int}> $records */
        $records = $this->connection->table($this->table)
            ->orderBy('identifier')
            ->get();

        $cassettes = [];

        foreach ($records as $record) {
            $updatedAt = is_string($record->updated_at)
                ? (int) strtotime($record->updated_at)
                : $record->updated_at->getTimestamp();

            $cassettes[] = [
                'identifier' => $record->identifier,
                'path' => "database://{$this->table}/{$record->identifier}",
                'size' => strlen($record->data),
                'updated_at' => $updatedAt,
                'signature' => $record->signature,
                'status' => (int) $record->status,
            ];
        }

        return $cassettes;
    }

    public function clear(?string $domain = null): int
    {
        $query = $this->connection->table($this->table);

        if ($domain !== null && $domain !== '') {
            $query->where('domain', $domain);
        }

        return $query->delete();
    }
}
