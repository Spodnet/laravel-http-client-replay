<?php

declare(strict_types=1);

use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;

it('displays an info message when listing with no cassettes', function () {
    $this->artisan('http-client-replay:list')
        ->expectsOutputToContain('No recorded HTTP replay cassettes found.')
        ->assertSuccessful();
});

it('lists recorded cassettes in a formatted table', function () {
    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $repo->store('strava/activities', [
        'identifier' => 'strava/activities',
        'signature' => 'GET https://www.strava.com/api/v3/athlete/activities',
        'response' => ['status' => 200, 'body' => '[]'],
    ]);

    $this->artisan('http-client-replay:list')
        ->expectsOutputToContain('strava/activities')
        ->expectsOutputToContain('Total cassettes: 1')
        ->assertSuccessful();
});

it('clears cassettes with http-client-replay:clear command', function () {
    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $repo->store('github/user', [
        'identifier' => 'github/user',
        'signature' => 'GET https://api.github.com/user',
        'response' => ['status' => 200, 'body' => '{}'],
    ]);

    expect($repo->has('github/user'))->toBeTrue();

    $this->artisan('http-client-replay:clear', ['--all' => true, '--force' => true])
        ->expectsOutputToContain('Successfully deleted')
        ->assertSuccessful();

    expect($repo->has('github/user'))->toBeFalse();
});

it('clears scoped domain cassettes with --domain option', function () {
    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $repo->store('strava/activities', [
        'identifier' => 'strava/activities',
        'signature' => 'GET https://api.strava.com/activities',
        'response' => ['status' => 200, 'body' => '[]'],
    ]);
    $repo->store('github/user', [
        'identifier' => 'github/user',
        'signature' => 'GET https://api.github.com/user',
        'response' => ['status' => 200, 'body' => '{}'],
    ]);

    $this->artisan('http-client-replay:clear', ['--domain' => 'strava', '--force' => true])
        ->expectsOutputToContain('cassettes for domain [strava]')
        ->assertSuccessful();

    expect($repo->has('strava/activities'))->toBeFalse()
        ->and($repo->has('github/user'))->toBeTrue();
});
