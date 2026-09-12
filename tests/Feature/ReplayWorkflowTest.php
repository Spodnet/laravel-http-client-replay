<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Enums\Mode;
use Spodnet\HttpClientReplay\Exceptions\CassetteNotFoundException;
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;

beforeEach(function () {
    HttpClientReplay::reset();
});

it('passes through live in off mode without recording cassettes', function () {
    HttpClientReplay::mode('off');

    // Simulate backend response
    Http::fake([
        'https://api.example.com/data' => Http::response(['status' => 'live'], 200),
    ]);

    $response = Http::get('https://api.example.com/data');

    expect($response->json('status'))->toBe('live');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    expect($repo->all())->toBeEmpty();
});

it('records cassettes in record mode', function () {
    HttpClientReplay::mode('record');

    Http::fake([
        'https://api.example.com/users' => Http::response(['id' => 123, 'name' => 'Alice'], 200),
    ]);

    $response = Http::get('https://api.example.com/users');

    expect($response->json('name'))->toBe('Alice');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $cassettes = $repo->all();

    expect($cassettes)->toHaveCount(1)
        ->and($cassettes[0]['signature'])->toContain('GET https://api.example.com/users');
});

it('replays response from existing cassette in replay mode', function () {
    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);

    // Pre-populate cassette
    $repo->store('custom/users', [
        'identifier' => 'custom/users',
        'signature' => 'GET https://api.example.com/users',
        'fingerprint' => 'hash123',
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'headers' => [],
            'body' => '',
        ],
        'response' => [
            'status' => 200,
            'headers' => ['Content-Type' => ['application/json']],
            'body' => json_encode(['id' => 999, 'name' => 'From Cassette']),
        ],
    ]);

    HttpClientReplay::mode('replay');
    HttpClientReplay::useCassette('custom/users');

    $response = Http::get('https://api.example.com/users');

    expect($response->status())->toBe(200)
        ->and($response->json('name'))->toBe('From Cassette')
        ->and($response->json('id'))->toBe(999);
});

it('throws CassetteNotFoundException in replay mode when cassette does not exist', function () {
    HttpClientReplay::mode('replay');
    HttpClientReplay::useCassette('non-existent-cassette');

    Http::get('https://api.example.com/missing');
})->throws(CassetteNotFoundException::class, 'No cassette found matching [GET https://api.example.com/missing]');

it('automatically records then replays on subsequent requests in auto mode', function () {
    HttpClientReplay::mode('auto');
    HttpClientReplay::useCassette('auto/posts');

    // Simulate backend response
    Http::fake([
        'https://api.example.com/posts' => Http::sequence()
            ->push(['id' => 1, 'origin' => 'first-live-call'], 200)
            ->push(['id' => 2, 'origin' => 'second-live-call'], 200),
    ]);

    // 1st request -> recorded live
    $response1 = Http::get('https://api.example.com/posts');
    expect($response1->json('origin'))->toBe('first-live-call');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    expect($repo->has('auto/posts'))->toBeTrue();

    // 2nd request -> replayed from cassette without consuming 2nd fake sequence response
    $response2 = Http::get('https://api.example.com/posts');
    expect($response2->json('origin'))->toBe('first-live-call');
});

it('respects URL scoping patterns and ignores non-matching requests', function () {
    HttpClientReplay::mode('record');
    HttpClientReplay::scope(['https://api.strava.com/*']);

    Http::fake([
        'https://api.strava.com/athlete' => Http::response(['athlete' => 'runner'], 200),
        'https://api.other.com/status' => Http::response(['status' => 'ignored'], 200),
    ]);

    Http::get('https://api.other.com/status');
    Http::get('https://api.strava.com/athlete');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $cassettes = $repo->all();

    expect($cassettes)->toHaveCount(1)
        ->and($cassettes[0]['signature'])->toContain('https://api.strava.com/athlete');
});

it('respects URL ignore patterns', function () {
    HttpClientReplay::mode('record');
    HttpClientReplay::ignore(['localhost*']);

    Http::fake([
        'https://localhost/api/test' => Http::response(['status' => 'local'], 200),
        'https://api.remote.com/test' => Http::response(['status' => 'remote'], 200),
    ]);

    Http::get('https://localhost/api/test');
    Http::get('https://api.remote.com/test');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $cassettes = $repo->all();

    expect($cassettes)->toHaveCount(1)
        ->and($cassettes[0]['signature'])->toContain('https://api.remote.com/test');
});

it('supports closures for temporary mode switches with record() and replay()', function () {
    HttpClientReplay::mode('off');

    Http::fake([
        'https://api.example.com/items' => Http::response(['item' => 1], 200),
    ]);

    HttpClientReplay::record(function () {
        Http::get('https://api.example.com/items');
    });

    expect(HttpClientReplay::mode())->toBe(Mode::Off);

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    expect($repo->all())->toHaveCount(1);
});

it('accepts and returns Mode enum values', function () {
    HttpClientReplay::mode(Mode::Record);
    expect(HttpClientReplay::mode())->toBe(Mode::Record)
        ->and(HttpClientReplay::isRecording())->toBeTrue();

    HttpClientReplay::mode('replay');
    expect(HttpClientReplay::mode())->toBe(Mode::Replay)
        ->and(HttpClientReplay::isReplaying())->toBeTrue();
});
