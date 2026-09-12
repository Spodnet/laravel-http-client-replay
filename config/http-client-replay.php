<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Replay Operating Mode
    |--------------------------------------------------------------------------
    |
    | Supported modes:
    | - 'off': Passthrough; live API calls only, no recording or intercepting.
    | - 'record': Executes live calls, sanitizes secrets, and writes cassettes to storage.
    | - 'replay': Never hits the live network; returns matching cassette or throws CassetteNotFoundException.
    | - 'auto': Serves from storage if a cassette exists, otherwise records from live.
    |
    */

    'mode' => env('HTTP_CLIENT_REPLAY_MODE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Defines where cassettes are persisted and retrieved.
    | Supported: 'file', 'disk', 'database'
    |
    */

    'driver' => env('HTTP_CLIENT_REPLAY_DRIVER', 'file'),

    'drivers' => [

        'file' => [
            'path' => env('HTTP_CLIENT_REPLAY_PATH', storage_path('http-client-replay/cassettes')),
        ],

        'disk' => [
            'disk' => env('HTTP_CLIENT_REPLAY_DISK', 'local'),
            'path' => env('HTTP_CLIENT_REPLAY_DISK_PATH', 'http-client-replay/cassettes'),
        ],

        'database' => [
            'connection' => env('HTTP_CLIENT_REPLAY_DB_CONNECTION'),
            'table' => env('HTTP_CLIENT_REPLAY_DB_TABLE', 'http_client_replay_cassettes'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scoped URL Patterns
    |--------------------------------------------------------------------------
    |
    | If populated, only outbound requests matching one of these URL patterns
    | or regexes will be intercepted for recording or replaying.
    | When empty, all requests are handled.
    |
    | Example: ['https://www.strava.com/*', '*.stripe.com/*']
    |
    */

    'scopes' => [],

    /*
    |--------------------------------------------------------------------------
    | Ignored URL Patterns
    |--------------------------------------------------------------------------
    |
    | Outbound requests matching any of these patterns will always pass
    | through to live network untouched (never recorded or replayed).
    |
    | Example: ['127.0.0.1*', 'localhost*']
    |
    */

    'ignore' => [
        '127.0.0.1*',
        'localhost*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Data Redaction
    |--------------------------------------------------------------------------
    |
    | Automatically redact sensitive headers, query parameters, and payload
    | fields from request and response recordings before storing.
    |
    */

    'redaction' => [

        'mask' => '[REDACTED]',

        'headers' => [
            'Authorization',
            'X-Api-Key',
            'api-key',
            'X-Auth-Token',
            'Cookie',
            'Set-Cookie',
        ],

        'query_parameters' => [
            'api_key',
            'key',
            'token',
            'secret',
            'access_token',
            'refresh_token',
            'client_secret',
        ],

        'body_fields' => [
            'client_secret',
            'refresh_token',
            'password',
            'token',
            'access_token',
            'secret',
            'api_key',
        ],

    ],

];
