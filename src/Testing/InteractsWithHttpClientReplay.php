<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Testing;

use PHPUnit\Framework\Assert;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;

trait InteractsWithHttpClientReplay
{
    /**
     * Specify a named cassette for subsequent HTTP requests or around a callback.
     */
    protected function useCassette(string $name, ?callable $callback = null): mixed
    {
        return HttpClientReplay::useCassette($name, $callback);
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
     * Reset HTTP client replay state between test executions.
     *
     * @after
     */
    protected function tearDownInteractsWithHttpClientReplay(): void
    {
        HttpClientReplay::reset();
    }
}
