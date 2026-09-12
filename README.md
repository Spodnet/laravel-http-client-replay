<div align="center">
    <h1>📼 Laravel HTTP Client Replay</h1>
    <p><strong>Zero-hack HTTP Record & Replay ("VCR") for modern Laravel applications.</strong></p>
</div>

<p align="center">
    <a href="https://packagist.org/packages/spodnet/laravel-http-client-replay"><img src="https://img.shields.io/packagist/v/spodnet/laravel-http-client-replay.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/spodnet/laravel-http-client-replay"><img src="https://img.shields.io/packagist/php-v/spodnet/laravel-http-client-replay.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/spodnet/laravel-http-client-replay"><img src="https://badge.laravel.cloud/badge/spodnet/laravel-http-client-replay?style=flat" alt="Laravel 12 and 13"></a>
    <a href="https://github.com/spodnet/laravel-http-client-replay/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/spodnet/laravel-http-client-replay/run-tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/spodnet/laravel-http-client-replay"><img src="https://img.shields.io/packagist/dt/spodnet/laravel-http-client-replay.svg?style=flat-square" alt="Total Downloads"></a>
</p>

---

## The Problem

When integrating third-party APIs (such as **Strava**, **Stripe**, **GitHub**, or **OpenAI**), local development and automated testing present major pain points:

- **Strict Rate Limits**: Running your test suite or reloading local features can burn through daily API quotas in minutes.
- **Flaky & Slow Tests**: Network latency, third-party outages, or changing remote data cause intermittent test failures in CI.
- **Tedious Mocking**: Manually constructing complex `Http::fake([...])` payloads is tedious, prone to falling out of date, and doesn't reflect real API responses.

## The Solution

`laravel-http-client-replay` records real outbound HTTP requests made via Laravel's native HTTP Client (`Http::*`), sanitizes sensitive secrets (bearer tokens, API keys, passwords), and saves them into clean JSON cassettes. Subsequent requests replay deterministically using Laravel's native `Http::fake()` without touching the internet.

- **Zero-Hack Architecture**: Leverages Laravel's native `Http::fake()` and `ResponseReceived` events. No custom Guzzle client wrappers or proxy servers needed.
- **4 Operating Modes**: `off`, `record`, `replay`, and `auto`.
- **Automatic Secret Redaction**: Masks `Authorization` headers, sensitive query parameters, and sensitive request/response JSON and form body fields before writing to disk.
- **Deterministic Fingerprinting**: Matches requests by HTTP method, normalized URL path, alphabetically sorted query parameters, and SHA-256 payload hash.
- **Pluggable Storage Drivers**: Save cassettes as local files, to Laravel Storage disks (e.g. S3), or to a database table.
- **Domain & URL Scoping**: Intercept only specific external APIs while letting internal/local traffic pass through.
- **Artisan Management**: Inspect and purge cassettes with built-in CLI commands.

---

## Installation

Install the package via Composer:

```bash
composer require spodnet/laravel-http-client-replay --dev
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="http-client-replay-config"
```

*(Optional)* If you plan to use the `database` cassette storage driver, publish and run the migration:

```bash
php artisan vendor:publish --tag="http-client-replay-migrations"
php artisan migrate
```

---

## Quickstart

### 1. Configure the Mode

Set the mode in your `.env` or `phpunit.xml`:

```dotenv
# Modes: auto | record | replay | off
HTTP_CLIENT_REPLAY_MODE=auto

# Storage Driver: file | disk | database
HTTP_CLIENT_REPLAY_DRIVER=file
```

### 2. Make Normal HTTP Requests

Continue using Laravel's standard `Http` facade. Everything works automatically:

```php
use Illuminate\Support\Facades\Http;

// In 'auto' mode:
// 1st run: Hits Strava live, sanitizes secrets, and saves a cassette.
// Subsequent runs: Replays the response instantly from the cassette!
$response = Http::withToken($token)->get('https://www.strava.com/api/v3/athlete/activities', [
    'page' => 1,
    'per_page' => 30,
]);

$activities = $response->json();
```

---

## Operating Modes

Configure the active mode in `config/http-client-replay.php` or via the `HTTP_CLIENT_REPLAY_MODE` environment variable:

| Mode | Live Network | Cassette Behavior | Use Case |
|---|---|---|---|
| `auto` *(default)* | Only when missing | Replays if cassette exists; records from live if missing | Daily local development & integration testing |
| `replay` | **Never** | Replays cassette; throws `CassetteNotFoundException` if missing | Strict CI environments & offline testing |
| `record` | **Always** | Executes live call, redacts secrets, and overwrites cassette | Refreshing cassettes with updated API responses |
| `off` | **Always** | Passthrough; completely disables recording and intercepting | Production or unmocked live workflows |

You can switch modes programmatically at runtime:

```php
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;

// Temporarily run a block in record mode
HttpClientReplay::record(function () use ($stravaClient) {
    $stravaClient->syncActivities();
});

// Temporarily run a block in replay mode
HttpClientReplay::replay(function () use ($stravaClient) {
    $stravaClient->syncActivities();
});

// Change mode dynamically using Mode enum or string
HttpClientReplay::mode(\Spodnet\HttpClientReplay\Enums\Mode::Replay);
// or: HttpClientReplay::mode('replay');
```

---

## Testing Ergonomics

### Using Named Cassettes

By default, cassettes are saved with a deterministic identifier derived from the request URL and payload. In tests, you can assign human-friendly cassette names:

```php
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;

it('fetches athlete activities', function () {
    HttpClientReplay::useCassette('strava/recent-activities');

    $response = Http::get('https://www.strava.com/api/v3/athlete/activities');

    expect($response->status())->toBe(200);
});
```

You can also pass a closure to `useCassette()` to scope the cassette to that specific block:

```php
$data = HttpClientReplay::useCassette('stripe/customer-create', function () {
    return $stripeService->createCustomer(['email' => 'jane@example.com']);
});
```

### Test Trait (`InteractsWithHttpClientReplay`)

Include the trait in your Pest or PHPUnit tests for convenience:

```php
use Spodnet\HttpClientReplay\Testing\InteractsWithHttpClientReplay;

uses(TestCase::class, InteractsWithHttpClientReplay::class);

it('stores customer charge cassette', function () {
    $this->useCassette('stripe/charge');

    $this->recordHttpClientReplay(function () {
        Http::post('https://api.stripe.com/v1/charges', ['amount' => 2000]);
    });

    $this->assertCassetteExists('stripe/charge');
});
```

---

## Sensitive Data Redaction

Cassettes should be safe to commit to version control. `laravel-http-client-replay` automatically redacts sensitive data before writing cassettes to storage.

Configure what to redact in `config/http-client-replay.php`:

```php
'redaction' => [
    'mask' => '[REDACTED]',

    // Headers sanitized (case-insensitive)
    'headers' => [
        'Authorization', // 'Bearer secret' -> 'Bearer [REDACTED]'
        'X-Api-Key',
        'api-key',
        'X-Auth-Token',
        'Cookie',
        'Set-Cookie',
    ],

    // Sensitive URL query parameters (e.g. ?api_key=secret)
    'query_parameters' => [
        'api_key',
        'key',
        'token',
        'secret',
        'access_token',
        'refresh_token',
        'client_secret',
    ],

    // Sensitive request & response body fields (JSON and form-urlencoded)
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
```

---

## Domain & URL Scoping

To avoid intercepting unrelated outbound requests (like internal microservices, AWS S3, or third-party webhooks), scope which URLs to handle:

### In Configuration (`config/http-client-replay.php`)

```php
// Only record / replay requests matching these patterns
'scopes' => [
    'https://www.strava.com/*',
    '*.stripe.com/*',
],

// Always pass through untouched
'ignore' => [
    '127.0.0.1*',
    'localhost*',
],
```

### At Runtime

```php
HttpClientReplay::scope(['https://api.github.com/*']);
HttpClientReplay::ignore(['localhost*']);
```

---

## Pluggable Storage Drivers

`laravel-http-client-replay` supports multiple storage drivers configured via `HTTP_CLIENT_REPLAY_DRIVER`:

### 1. `file` Driver (Default)
Stores cassettes as human-readable `.json` files on local disk:
```dotenv
HTTP_CLIENT_REPLAY_DRIVER=file
HTTP_CLIENT_REPLAY_PATH="storage/http-client-replay/cassettes"
```

### 2. `disk` Driver
Stores cassettes on any configured Laravel Filesystem disk (e.g. `s3`, `local`):
```dotenv
HTTP_CLIENT_REPLAY_DRIVER=disk
HTTP_CLIENT_REPLAY_DISK=local
HTTP_CLIENT_REPLAY_DISK_PATH="http-client-replay/cassettes"
```

### 3. `database` Driver
Persists cassettes in a database table:
```dotenv
HTTP_CLIENT_REPLAY_DRIVER=database
HTTP_CLIENT_REPLAY_DB_TABLE="http_client_replay_cassettes"
```

---

## Artisan Commands

### List Recorded Cassettes

View all recorded cassettes along with their size, age, HTTP status, and request signature:

```bash
php artisan http-client-replay:list
```

Example output:
```text
+--------------------------+-------------------------------------------------------------+--------+--------+---------------+
| Cassette                 | Signature                                                   | Status | Size   | Age           |
+--------------------------+-------------------------------------------------------------+--------+--------+---------------+
| strava/recent-activities | GET https://www.strava.com/api/v3/athlete/activities?page=1 | 200    | 2.4 KB | 2 hours ago   |
| stripe/create-customer   | POST https://api.stripe.com/v1/customers [body:3a8f1...]    | 200    | 1.1 KB | 1 day ago     |
+--------------------------+-------------------------------------------------------------+--------+--------+---------------+
Total cassettes: 2
```

### Clear Recorded Cassettes

Delete all cassettes or filter by domain:

```bash
# Clear all cassettes (with confirmation prompt)
php artisan http-client-replay:clear --all

# Clear only cassettes for a specific domain
php artisan http-client-replay:clear --domain=strava.com

# Force delete without confirmation in CI or scripts
php artisan http-client-replay:clear --all --force
```

---

## Testing & Quality

Run full validation (Static analysis, Pint linting, 100% type coverage, and parallel Pest unit tests):

```bash
composer test
```

Or individual commands:

```bash
composer analyse       # PHPStan (Level 7)
composer lint:check    # Laravel Pint
composer test:types    # Pest Type Coverage (100% enforcement)
composer test:unit     # Parallel Pest test suite
```

---

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please review [CONTRIBUTING.md](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review our [security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Martin Wheatley](https://github.com/spodnet)
- [All Contributors](../../contributors)

## License

Laravel HTTP Client Replay is open-source software licensed under the [MIT license](LICENSE.md).
