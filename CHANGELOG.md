# Release Notes

## [Unreleased](https://github.com/spodnet/laravel-http-client-replay/compare/v0.1.0...main)

## [v0.1.0](https://github.com/spodnet/laravel-http-client-replay/releases/tag/v0.1.0) - 2026-09-12

Initial release of **Laravel HTTP Client Replay** (`spodnet/laravel-http-client-replay`): zero-hack HTTP record and replay ("VCR") for modern Laravel applications.

### What's Changed
- **Zero-Hack Architecture**: Integrates directly with Laravel's native HTTP Client (`Http::fake()` and `ResponseReceived` events) without custom Guzzle wrappers or proxy servers.
- **4 Operating Modes**:
  - `auto`: Replays from cassette if found; otherwise records from live network and saves.
  - `record`: Always executes live requests and records/overwrites cassettes.
  - `replay`: Never touches live network; returns cassette or throws `CassetteNotFoundException`.
  - `off`: Passthrough mode; disables recording and interception.
- **Automatic Secret Redaction**: Configurable masking of sensitive headers (`Authorization`, `X-Api-Key`, `Cookie`), query parameters, and JSON/form body fields before writing to storage.
- **Deterministic Request Fingerprinting**: Matches requests by HTTP method, normalized URL path, sorted query parameters, and SHA-256 payload hash.
- **Pluggable Storage Drivers**:
  - `file`: Local JSON files (default).
  - `disk`: Laravel Storage disks (e.g. S3, local, custom).
  - `database`: Relational database storage via migration.
- **URL Scoping & Filtering**: Define include scopes and ignore patterns to intercept only target third-party APIs while allowing internal traffic to pass through.
- **Artisan Commands**:
  - `http-client-replay:list`: Formatted table of recorded cassettes with sizes, status codes, and timestamps.
  - `http-client-replay:clear`: Delete cassettes by domain or clear all cassettes.
- **Testing Helpers**:
  - `InteractsWithHttpClientReplay` trait for Pest and PHPUnit tests.
  - `HttpClientReplay::useCassette()`, `HttpClientReplay::record()`, `HttpClientReplay::replay()`, `assertCassetteExists()`, and `assertCassetteMissing()`.
- **Laravel 12 and 13 Support**: Full compatibility with PHP 8.3, 8.4, and 8.5 on Ubuntu, macOS, and Windows.
