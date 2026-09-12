<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Spodnet\HttpClientReplay\HttpClientReplayServiceProvider;

it('registers publishable config with expected tags', function (string $tag) {
    /** @var array<string, string> $paths */
    $paths = ServiceProvider::pathsToPublish(HttpClientReplayServiceProvider::class, $tag);

    $normalized = collect($paths)
        ->mapWithKeys(fn (string $target, string $source): array => [(string) realpath($source) => $target])
        ->all();

    $expectedSource = (string) realpath(__DIR__.'/../../config/http-client-replay.php');
    $expectedTarget = config_path('http-client-replay.php');

    expect($normalized)->toHaveKey($expectedSource)
        ->and($normalized[$expectedSource])->toBe($expectedTarget);
})->with([
    'http-client-replay',
    'http-client-replay-config',
    'laravel-http-client-replay',
    'laravel-http-client-replay-config',
]);

it('registers publishable migrations with expected tags', function (string $tag) {
    /** @var array<string, string> $paths */
    $paths = ServiceProvider::pathsToPublish(HttpClientReplayServiceProvider::class, $tag);

    $normalized = collect($paths)
        ->mapWithKeys(fn (string $target, string $source): array => [(string) realpath($source) => $target])
        ->all();

    $expectedSource = (string) realpath(__DIR__.'/../../database/migrations');
    $expectedTarget = database_path('migrations');

    expect($normalized)->toHaveKey($expectedSource)
        ->and($normalized[$expectedSource])->toBe($expectedTarget);

    $migrationFiles = glob($expectedSource.'/*_create_http_client_replay_cassettes_table.php');
    expect($migrationFiles)->not->toBeEmpty();
})->with([
    'http-client-replay',
    'http-client-replay-migrations',
    'laravel-http-client-replay',
    'laravel-http-client-replay-migrations',
]);
