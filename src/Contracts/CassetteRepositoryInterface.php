<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Contracts;

interface CassetteRepositoryInterface
{
    /**
     * Determine whether a cassette exists by identifier.
     */
    public function has(string $identifier): bool;

    /**
     * Find a cassette by identifier.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $identifier): ?array;

    /**
     * Store cassette data under the given identifier.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(string $identifier, array $data): void;

    /**
     * Delete a cassette by identifier.
     */
    public function delete(string $identifier): bool;

    /**
     * Retrieve metadata for all cassettes.
     *
     * @return array<int, array{identifier: string, path: string, size: int, updated_at: int, signature: string, status: int}>
     */
    public function all(): array;

    /**
     * Clear all cassettes or those matching an optional domain.
     *
     * @return int Number of deleted cassettes
     */
    public function clear(?string $domain = null): int;
}
