<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Facades;

use Illuminate\Support\Facades\Facade;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Contracts\RedactorInterface;
use Spodnet\HttpClientReplay\HttpClientReplayManager;

/**
 * @method static \Spodnet\HttpClientReplay\Enums\Mode|HttpClientReplayManager mode(\Spodnet\HttpClientReplay\Enums\Mode|string|null $mode = null)
 * @method static bool isOff()
 * @method static bool isRecording()
 * @method static bool isReplaying()
 * @method static bool isAuto()
 * @method static mixed useCassette(string $name, ?callable $callback = null, \DateTimeInterface|\DateInterval|int|string|null $ttl = null)
 * @method static mixed remember(string $name, \DateTimeInterface|\DateInterval|int|string $ttl, callable $callback)
 * @method static mixed rememberForever(string $name, callable $callback)
 * @method static HttpClientReplayManager ttl(\DateTimeInterface|\DateInterval|int|string|null $ttl)
 * @method static HttpClientReplayManager forever()
 * @method static mixed withTtl(\DateTimeInterface|\DateInterval|int|string|null $ttl, callable $callback)
 * @method static bool touch(string $identifier, \DateTimeInterface|\DateInterval|int|string|null $ttl = null)
 * @method static ?string activeCassette()
 * @method static mixed record(callable $callback)
 * @method static mixed replay(callable $callback)
 * @method static mixed fake(?callable $callback = null)
 * @method static HttpClientReplayManager scope(array<array-key, mixed>|string $patterns, array<string, mixed> $options = [])
 * @method static HttpClientReplayManager ignore(array<int, string>|string $patterns)
 * @method static void reset()
 * @method static void bootInterceptor()
 * @method static CassetteRepositoryInterface repository()
 * @method static RedactorInterface redactor()
 *
 * @see HttpClientReplayManager
 */
class HttpClientReplay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'http-client-replay';
    }
}
