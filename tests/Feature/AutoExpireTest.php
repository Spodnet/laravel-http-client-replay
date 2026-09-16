<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Enums\Mode;
use Spodnet\HttpClientReplay\Events\CassetteExpired;
use Spodnet\HttpClientReplay\Exceptions\CassetteExpiredException;
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;

beforeEach(function () {
    HttpClientReplay::reset();
    Carbon::setTestNow();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('replays fresh cassette when within driver TTL', function () {
    config()->set('http-client-replay.drivers.file.ttl', 3600);
    HttpClientReplay::mode('auto');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('test/time', [
        'identifier' => 'test/time',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/time',
        'fingerprint' => 'hash-time',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/time',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['time' => '12:00:00']),
        ],
    ]);

    HttpClientReplay::useCassette('test/time');

    // 30 minutes later - still fresh (TTL 3600s)
    Carbon::setTestNow('2026-09-16 12:30:00');

    $response = Http::get('https://api.example.com/time');

    expect($response->json('time'))->toBe('12:00:00');
});

it('auto-expires stale cassette and re-records live in auto mode when driver TTL is exceeded', function () {
    config()->set('http-client-replay.drivers.file.ttl', 60);
    HttpClientReplay::mode('auto');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('test/expire', [
        'identifier' => 'test/expire',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/expire',
        'fingerprint' => 'hash-expire',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/expire',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['version' => 'stale']),
        ],
    ]);

    HttpClientReplay::useCassette('test/expire');

    // Advance time past 60s TTL
    Carbon::setTestNow('2026-09-16 12:01:05');

    // Mock the live endpoint that should be hit because the cassette expired
    Http::fake([
        'https://api.example.com/expire' => Http::response(['version' => 'fresh'], 200),
    ]);

    $response = Http::get('https://api.example.com/expire');

    expect($response->json('version'))->toBe('fresh');

    // Verify the cassette was updated with fresh content and new timestamp
    $updatedCassette = $repo->find('test/expire');
    expect($updatedCassette)->not->toBeNull()
        ->and(json_decode((string) $updatedCassette['response']['body'], true)['version'])->toBe('fresh')
        ->and($updatedCassette['recorded_at'])->toBe('2026-09-16T12:01:05+00:00');
});

it('respects scope-level TTL option over driver-level TTL', function () {
    config()->set('http-client-replay.drivers.file.ttl', 86400); // 1 day
    config()->set('http-client-replay.scopes', [
        'https://api.fast-expire.com/*' => ['ttl' => 10], // 10 seconds
    ]);
    HttpClientReplay::mode('auto');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('scoped/data', [
        'identifier' => 'scoped/data',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.fast-expire.com/data',
        'fingerprint' => 'hash-scoped',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.fast-expire.com/data',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['value' => 'old']),
        ],
    ]);

    HttpClientReplay::useCassette('scoped/data');

    // 15 seconds later - expired under scope TTL (10s), even though driver TTL is 1 day
    Carbon::setTestNow('2026-09-16 12:00:15');

    Http::fake([
        'https://api.fast-expire.com/data' => Http::response(['value' => 'new'], 200),
    ]);

    $response = Http::get('https://api.fast-expire.com/data');

    expect($response->json('value'))->toBe('new');
});

it('respects runtime scope options with TTL', function () {
    HttpClientReplay::mode('auto');
    HttpClientReplay::scope('https://api.runtime-scoped.com/*', ['ttl' => 30]);

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('runtime/scope', [
        'identifier' => 'runtime/scope',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.runtime-scoped.com/check',
        'fingerprint' => 'hash-runtime-scope',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.runtime-scoped.com/check',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['status' => 'cached']),
        ],
    ]);

    HttpClientReplay::useCassette('runtime/scope');

    // 45 seconds later -> expired
    Carbon::setTestNow('2026-09-16 12:00:45');

    Http::fake([
        'https://api.runtime-scoped.com/check' => Http::response(['status' => 'live'], 200),
    ]);

    $response = Http::get('https://api.runtime-scoped.com/check');

    expect($response->json('status'))->toBe('live');
});

it('respects request-level Http::withOptions replay_ttl', function () {
    config()->set('http-client-replay.ttl', 86400); // 1 day default
    HttpClientReplay::mode('auto');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('request/options', [
        'identifier' => 'request/options',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/options',
        'fingerprint' => 'hash-options',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/options',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['val' => 'stale']),
        ],
    ]);

    HttpClientReplay::useCassette('request/options');

    // 10 seconds later
    Carbon::setTestNow('2026-09-16 12:00:10');

    Http::fake([
        'https://api.example.com/options' => Http::response(['val' => 'refreshed'], 200),
    ]);

    // Request-level option overrides global TTL with 5 seconds
    $response = Http::withOptions(['replay_ttl' => 5])
        ->get('https://api.example.com/options');

    expect($response->json('val'))->toBe('refreshed');
});

it('respects request-level X-Replay-TTL header', function () {
    config()->set('http-client-replay.ttl', 86400);
    HttpClientReplay::mode('auto');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('request/header', [
        'identifier' => 'request/header',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/header',
        'fingerprint' => 'hash-header',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/header',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['val' => 'stale']),
        ],
    ]);

    HttpClientReplay::useCassette('request/header');

    // 10 seconds later
    Carbon::setTestNow('2026-09-16 12:00:10');

    Http::fake([
        'https://api.example.com/header' => Http::response(['val' => 'refreshed'], 200),
    ]);

    $response = Http::withHeaders(['X-Replay-TTL' => '5'])
        ->get('https://api.example.com/header');

    expect($response->json('val'))->toBe('refreshed');
});

it('supports remember() method mirroring Cache::remember()', function () {
    HttpClientReplay::mode('auto');

    Carbon::setTestNow('2026-09-16 12:00:00');

    Http::fake([
        'https://api.example.com/profile' => function () {
            $name = now()->greaterThan('2026-09-16 13:00:00') ? 'Alice Updated' : 'Alice';

            return Http::response(['name' => $name], 200);
        },
    ]);

    // 1. Initial call records cassette with 1 hour TTL
    $response1 = HttpClientReplay::remember('user/profile', 3600, function () {
        return Http::get('https://api.example.com/profile');
    });

    expect($response1->json('name'))->toBe('Alice');

    // 2. Advance 30 mins -> still within TTL -> replays
    Carbon::setTestNow('2026-09-16 12:30:00');

    $response2 = HttpClientReplay::remember('user/profile', 3600, function () {
        return Http::get('https://api.example.com/profile');
    });

    expect($response2->json('name'))->toBe('Alice');

    // 3. Advance past 1 hour -> expired -> runs callback and records fresh
    Carbon::setTestNow('2026-09-16 13:01:00');

    $response3 = HttpClientReplay::remember('user/profile', 3600, function () {
        return Http::get('https://api.example.com/profile');
    });

    expect($response3->json('name'))->toBe('Alice Updated');
});

it('supports rememberForever() method', function () {
    config()->set('http-client-replay.ttl', 10); // 10s default
    HttpClientReplay::mode('auto');

    Carbon::setTestNow('2026-09-16 12:00:00');

    Http::fake([
        'https://api.example.com/permanent' => Http::response(['key' => 'initial'], 200),
    ]);

    HttpClientReplay::rememberForever('perm/data', function () {
        Http::get('https://api.example.com/permanent');
    });

    // 1 day later -> should still replay because of rememberForever
    Carbon::setTestNow('2026-09-17 12:00:00');

    $response = HttpClientReplay::rememberForever('perm/data', function () {
        return Http::get('https://api.example.com/permanent');
    });

    expect($response->json('key'))->toBe('initial');
});

it('supports touch() to refresh cassette recorded timestamp and extend expiry', function () {
    config()->set('http-client-replay.ttl', 60);
    HttpClientReplay::mode('auto');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('touch/data', [
        'identifier' => 'touch/data',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/touch',
        'fingerprint' => 'hash-touch',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/touch',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['val' => 'touched']),
        ],
    ]);

    // Advance 50 seconds (10 seconds left before expiration)
    Carbon::setTestNow('2026-09-16 12:00:50');

    // Touch the cassette to refresh timestamp
    $touched = HttpClientReplay::touch('touch/data');
    expect($touched)->toBeTrue();

    // Advance another 50 seconds (total 100s from original record, but 50s from touch)
    Carbon::setTestNow('2026-09-16 12:01:40');

    HttpClientReplay::useCassette('touch/data');
    $response = Http::get('https://api.example.com/touch');

    expect($response->json('val'))->toBe('touched');
});

it('throws CassetteExpiredException in replay mode when cassette has expired', function () {
    config()->set('http-client-replay.ttl', 60);
    HttpClientReplay::mode(Mode::Replay);

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('strict/replay', [
        'identifier' => 'strict/replay',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/strict',
        'fingerprint' => 'hash-strict',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/strict',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['status' => 'ok']),
        ],
    ]);

    HttpClientReplay::useCassette('strict/replay');

    // Advance past TTL in replay mode
    Carbon::setTestNow('2026-09-16 12:01:30');

    Http::get('https://api.example.com/strict');
})->throws(CassetteExpiredException::class, 'has expired');

it('dispatches CassetteExpired event upon expiration', function () {
    Event::fake([CassetteExpired::class]);

    config()->set('http-client-replay.ttl', 30);
    HttpClientReplay::mode('auto');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    $repo->store('event/test', [
        'identifier' => 'event/test',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/event',
        'fingerprint' => 'hash-event',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/event',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => [],
            'body' => json_encode(['event' => 'triggered']),
        ],
    ]);

    HttpClientReplay::useCassette('event/test');

    Carbon::setTestNow('2026-09-16 12:00:45');

    Http::fake([
        'https://api.example.com/event' => Http::response(['event' => 'fresh'], 200),
    ]);

    Http::get('https://api.example.com/event');

    Event::assertDispatched(CassetteExpired::class, function (CassetteExpired $event) {
        return $event->identifier === 'event/test'
            && $event->age === 45
            && $event->ttl === 30;
    });
});

it('clears only expired cassettes with clear --expired command', function () {
    config()->set('http-client-replay.ttl', 60);

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    Carbon::setTestNow('2026-09-16 12:00:00');

    // Expired cassette (recorded at 12:00:00)
    $repo->store('expired/one', [
        'identifier' => 'expired/one',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/1',
        'fingerprint' => 'hash-1',
        'request' => ['method' => 'GET', 'url' => 'https://api.example.com/1', 'headers' => [], 'body' => ''],
        'response' => ['status' => 200, 'headers' => [], 'body' => '1'],
    ]);

    // Advance 50 seconds
    Carbon::setTestNow('2026-09-16 12:00:50');

    // Fresh cassette (recorded at 12:00:50)
    $repo->store('fresh/two', [
        'identifier' => 'fresh/two',
        'recorded_at' => now()->toIso8601String(),
        'signature' => 'GET https://api.example.com/2',
        'fingerprint' => 'hash-2',
        'request' => ['method' => 'GET', 'url' => 'https://api.example.com/2', 'headers' => [], 'body' => ''],
        'response' => ['status' => 200, 'headers' => [], 'body' => '2'],
    ]);

    // Advance to 12:01:10 (first is 70s old -> expired; second is 20s old -> fresh)
    Carbon::setTestNow('2026-09-16 12:01:10');

    $this->artisan('http-client-replay:clear', ['--expired' => true, '--force' => true])
        ->expectsOutputToContain('Successfully deleted 1')
        ->assertSuccessful();

    expect($repo->has('expired/one'))->toBeFalse()
        ->and($repo->has('fresh/two'))->toBeTrue();
});

it('accepts DateTimeInterface and CarbonInterval for TTL', function () {
    HttpClientReplay::mode('auto');

    Carbon::setTestNow('2026-09-16 12:00:00');

    Http::fake([
        'https://api.example.com/interval' => function () {
            $call = now()->greaterThan('2026-09-16 12:10:00') ? 2 : 1;

            return Http::response(['call' => $call], 200);
        },
    ]);

    // Using now()->addMinutes(10)
    $res1 = HttpClientReplay::remember('test/datetime', now()->addMinutes(10), function () {
        return Http::get('https://api.example.com/interval');
    });
    expect($res1->json('call'))->toBe(1);

    // 5 mins later -> still fresh
    Carbon::setTestNow('2026-09-16 12:05:00');
    $res2 = HttpClientReplay::remember('test/datetime', now()->addMinutes(10), function () {
        return Http::get('https://api.example.com/interval');
    });
    expect($res2->json('call'))->toBe(1);

    // 15 mins later -> expired -> hits live call with call=2
    Carbon::setTestNow('2026-09-16 12:15:00');
    $res3 = HttpClientReplay::remember('test/datetime', CarbonInterval::minutes(10), function () {
        return Http::get('https://api.example.com/interval');
    });
    expect($res3->json('call'))->toBe(2);
});
