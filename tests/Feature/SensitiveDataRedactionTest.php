<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;

beforeEach(function () {
    HttpClientReplay::reset();
    HttpClientReplay::mode('record');
});

it('redacts Authorization and API key headers in recorded cassette', function () {
    HttpClientReplay::useCassette('auth/test');

    Http::fake([
        'https://api.example.com/me' => Http::response(['ok' => true], 200),
    ]);

    Http::withHeaders([
        'Authorization' => 'Bearer secret_access_token_123',
        'X-Api-Key' => 'super_secret_api_key_456',
        'Accept' => 'application/json',
    ])->get('https://api.example.com/me');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $cassette = $repo->find('auth/test');

    expect($cassette)->not->toBeNull();

    $headers = $cassette['request']['headers'];
    expect($headers['Authorization'][0])->toBe('Bearer [REDACTED]')
        ->and($headers['X-Api-Key'][0])->toBe('[REDACTED]')
        ->and($headers['Accept'][0])->toBe('application/json');
});

it('redacts sensitive query parameters in URL', function () {
    HttpClientReplay::useCassette('query/test');

    Http::fake([
        'https://api.example.com/search*' => Http::response(['results' => []], 200),
    ]);

    Http::get('https://api.example.com/search?api_key=secret_param_123&query=laravel&token=abc');

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $cassette = $repo->find('query/test');

    expect($cassette)->not->toBeNull();

    $url = $cassette['request']['url'];
    expect($url)->toContain('api_key=%5BREDACTED%5D')
        ->and($url)->toContain('token=%5BREDACTED%5D')
        ->and($url)->toContain('query=laravel');
});

it('redacts sensitive fields in JSON request and response bodies', function () {
    HttpClientReplay::useCassette('payloads/test');

    Http::fake([
        'https://api.example.com/oauth/token' => Http::response([
            'token_type' => 'Bearer',
            'access_token' => 'live_oauth_access_token',
            'refresh_token' => 'live_oauth_refresh_token',
            'expires_in' => 7200,
        ], 200),
    ]);

    Http::asJson()->post('https://api.example.com/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => 'client_123',
        'client_secret' => 'super_secret_oauth_secret',
        'nested' => [
            'password' => 'secret_password',
            'user' => 'john',
        ],
    ]);

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $cassette = $repo->find('payloads/test');

    expect($cassette)->not->toBeNull();

    // Check request body redaction
    $requestBody = json_decode((string) $cassette['request']['body'], true);
    expect($requestBody['client_secret'])->toBe('[REDACTED]')
        ->and($requestBody['nested']['password'])->toBe('[REDACTED]')
        ->and($requestBody['client_id'])->toBe('client_123')
        ->and($requestBody['nested']['user'])->toBe('john');

    // Check response body redaction
    $responseBody = json_decode((string) $cassette['response']['body'], true);
    expect($responseBody['access_token'])->toBe('[REDACTED]')
        ->and($responseBody['refresh_token'])->toBe('[REDACTED]')
        ->and($responseBody['token_type'])->toBe('Bearer')
        ->and($responseBody['expires_in'])->toBe(7200);
});

it('redacts sensitive fields in form-urlencoded request bodies', function () {
    HttpClientReplay::useCassette('form/test');

    Http::fake([
        'https://api.example.com/oauth/form' => Http::response(['status' => 'ok'], 200),
    ]);

    Http::asForm()->post('https://api.example.com/oauth/form', [
        'client_secret' => 'my_form_secret',
        'refresh_token' => 'my_form_refresh',
        'grant_type' => 'refresh_token',
    ]);

    /** @var CassetteRepositoryInterface $repo */
    $repo = app(CassetteRepositoryInterface::class);
    $cassette = $repo->find('form/test');

    expect($cassette)->not->toBeNull();

    $body = (string) $cassette['request']['body'];
    parse_str($body, $parsed);

    expect($parsed['client_secret'])->toBe('[REDACTED]')
        ->and($parsed['refresh_token'])->toBe('[REDACTED]')
        ->and($parsed['grant_type'])->toBe('refresh_token');
});
