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
    | Default Cassette Expiration (TTL)
    |--------------------------------------------------------------------------
    |
    | Defines the default duration (in seconds) a cassette remains valid.
    | When set to null, cassettes never expire unless configured on a per-driver,
    | per-scope, or per-request basis.
    |
    */

    'ttl' => env('HTTP_CLIENT_REPLAY_TTL', null),

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
            'ttl' => env('HTTP_CLIENT_REPLAY_FILE_TTL', null),
        ],

        'disk' => [
            'disk' => env('HTTP_CLIENT_REPLAY_DISK', 'local'),
            'path' => env('HTTP_CLIENT_REPLAY_DISK_PATH', 'http-client-replay/cassettes'),
            'ttl' => env('HTTP_CLIENT_REPLAY_DISK_TTL', null),
        ],

        'database' => [
            'connection' => env('HTTP_CLIENT_REPLAY_DB_CONNECTION'),
            'table' => env('HTTP_CLIENT_REPLAY_DB_TABLE', 'http_client_replay_cassettes'),
            'ttl' => env('HTTP_CLIENT_REPLAY_DB_TTL', null),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scoped URL Patterns & Options
    |--------------------------------------------------------------------------
    |
    | If populated, only outbound requests matching one of these URL patterns
    | or regexes will be intercepted for recording or replaying.
    | When empty, all requests are handled.
    |
    | Patterns can be defined as simple strings or keyed with scope options:
    | Examples:
    |   ['https://api.github.com/*']
    |   ['https://www.strava.com/*' => ['ttl' => 3600]]
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
