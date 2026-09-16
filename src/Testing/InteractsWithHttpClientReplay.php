<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Testing;

use DateInterval;
use DateTimeInterface;
use PHPUnit\Framework\Assert;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;
use Spodnet\HttpClientReplay\HttpClientReplayManager;

trait InteractsWithHttpClientReplay
{
    /**
     * Specify a named cassette for subsequent HTTP requests or around a callback.
     */
    protected function useCassette(string $name, ?callable $callback = null, DateTimeInterface|DateInterval|int|string|null $ttl = null): mixed
    {
        return HttpClientReplay::useCassette($name, $callback, $ttl);
    }

    /**
     * Remember an HTTP response in a named cassette for a given duration.
     */
    protected function rememberHttpClientReplay(string $name, DateTimeInterface|DateInterval|int|string $ttl, callable $callback): mixed
    {
        return HttpClientReplay::remember($name, $ttl, $callback);
    }

    /**
     * Execute a callback under a specific TTL context.
     */
    protected function withHttpClientReplayTtl(DateTimeInterface|DateInterval|int|string|null $ttl, callable $callback): mixed
    {
        return HttpClientReplay::withTtl($ttl, $callback);
    }

    /**
     * Execute a closure forced into record mode.
     */
    protected function recordHttpClientReplay(callable $callback): mixed
    {
        return HttpClientReplay::record($callback);
    }

    /**
     * Execute a closure forced into replay mode.
     */
    protected function replayHttpClientReplay(callable $callback): mixed
    {
        return HttpClientReplay::replay($callback);
    }

    /**
     * Assert that a cassette exists in storage.
     */
    protected function assertCassetteExists(string $identifier): void
    {
        /** @var CassetteRepositoryInterface $repo */
        $repo = app(CassetteRepositoryInterface::class);

        Assert::assertTrue(
            $repo->has($identifier),
            "Failed asserting that cassette [{$identifier}] exists.",
        );
    }

    /**
     * Assert that a cassette does not exist in storage.
     */
    protected function assertCassetteMissing(string $identifier): void
    {
        /** @var CassetteRepositoryInterface $repo */
        $repo = app(CassetteRepositoryInterface::class);

        Assert::assertFalse(
            $repo->has($identifier),
            "Failed asserting that cassette [{$identifier}] does not exist.",
        );
    }

    /**
     * Assert that a cassette exists in storage and is expired according to its resolved TTL.
     */
    protected function assertCassetteExpired(string $identifier): void
    {
        /** @var CassetteRepositoryInterface $repo */
        $repo = app(CassetteRepositoryInterface::class);
        $cassette = $repo->find($identifier);

        Assert::assertNotNull(
            $cassette,
            "Failed asserting that cassette [{$identifier}] exists before checking expiry.",
        );

        /** @var HttpClientReplayManager $manager */
        $manager = app(HttpClientReplayManager::class);

        Assert::assertTrue(
            $manager->isExpiredCassetteData($cassette),
            "Failed asserting that cassette [{$identifier}] is expired.",
        );
    }

    /**
     * Assert that a cassette exists in storage and is fresh (not expired).
     */
    protected function assertCassetteFresh(string $identifier): void
    {
        /** @var CassetteRepositoryInterface $repo */
        $repo = app(CassetteRepositoryInterface::class);
        $cassette = $repo->find($identifier);

        Assert::assertNotNull(
            $cassette,
            "Failed asserting that cassette [{$identifier}] exists before checking freshness.",
        );

        /** @var HttpClientReplayManager $manager */
        $manager = app(HttpClientReplayManager::class);

        Assert::assertFalse(
            $manager->isExpiredCassetteData($cassette),
            "Failed asserting that cassette [{$identifier}] is fresh.",
        );
    }

    /**
     * Reset HTTP client replay state between test executions.
     *
     * @after
     */
    protected function tearDownInteractsWithHttpClientReplay(): void
    {
        HttpClientReplay::reset();
    }
}
