---
name: laravel-http-client-replay-development
description: >
  Record and replay external HTTP interactions in Laravel applications using spodnet/laravel-http-client-replay.
license: MIT
metadata:
  author: Martin Wheatley
---

# Laravel HTTP Client Replay Integration

Use this skill when a Laravel application needs to record and replay external HTTP client requests ("VCR") to avoid hitting rate limits during development or testing.

## Primary Goal

- Apply `spodnet/laravel-http-client-replay` to intercept outbound `Http` requests cleanly with zero hacks, storing sanitized cassettes and replaying them deterministically.

## Workflow

### 1. Configuration & Modes

Configure the operating mode in `.env` or `config/http-client-replay.php`:

```dotenv
# Modes: auto | record | replay | off
HTTP_CLIENT_REPLAY_MODE=auto

# Storage driver: file | disk | database
HTTP_CLIENT_REPLAY_DRIVER=file
```

- `auto`: Serves from cassette if available; otherwise records live and saves.
- `record`: Always makes live calls and overwrites cassettes.
- `replay`: Never hits live network; returns cassette or throws `CassetteNotFoundException`.
- `off`: Passthrough; disables interception completely.

### 2. Testing Workflows

Use the `InteractsWithHttpClientReplay` trait or `HttpClientReplay` facade in Pest or PHPUnit:

```php
use Spodnet\HttpClientReplay\Enums\Mode;
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;

// Set mode via enum or string
HttpClientReplay::mode(Mode::Auto);

// Named cassette
HttpClientReplay::useCassette('strava/recent-activities');
$activities = $client->getActivities();

// Or scoped execution
$result = HttpClientReplay::record(fn () => $client->fetch());
$cached = HttpClientReplay::replay(fn () => $client->fetch());

// Auto-expiring cassette (mirrors Cache::remember)
$activities = HttpClientReplay::remember('strava/activities', now()->addHours(2), function () use ($client) {
    return $client->getActivities();
});
```

### 3. URL Scoping & Filtering

Limit interception to specific third-party APIs and optionally define per-scope TTLs:

```php
HttpClientReplay::scope([
    'https://www.strava.com/*' => ['ttl' => 3600],
    '*.stripe.com/*',
]);
HttpClientReplay::ignore(['127.0.0.1*', 'localhost*']);
```

### 4. Console Management

Manage cassettes via Artisan:

```bash
php artisan http-client-replay:list
php artisan http-client-replay:clear --expired
php artisan http-client-replay:clear --domain=strava
php artisan http-client-replay:clear --all --force
```

## Anti-patterns

- Never commit unredacted credentials; configure sensitive keys under `http-client-replay.redaction`.
- Do not bypass `Http::fake()` when testing third-party APIs in replay mode.
