<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay;

use Carbon\Carbon;
use DateInterval;
use DateTimeInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Contracts\RedactorInterface;
use Spodnet\HttpClientReplay\Enums\Mode;
use Spodnet\HttpClientReplay\Events\CassetteExpired;
use Spodnet\HttpClientReplay\Events\CassetteRecorded;
use Spodnet\HttpClientReplay\Exceptions\CassetteExpiredException;
use Spodnet\HttpClientReplay\Exceptions\CassetteNotFoundException;
use Spodnet\HttpClientReplay\Matchers\RequestFingerprint;

class HttpClientReplayManager
{
    use InteractsWithTime;

    protected ?string $activeCassette = null;

    protected ?Mode $overrideMode = null;

    /**
     * Runtime TTL override (false when not set, null for indefinite, int for seconds).
     */
    protected DateTimeInterface|DateInterval|int|string|null|false $runtimeTtl = false;

    /**
     * @var array<int|string, mixed>|null
     */
    protected ?array $runtimeScopes = null;

    /**
     * @var array<int, string>|null
     */
    protected ?array $runtimeIgnore = null;

    /**
     * Track fingerprints of requests that were replayed to prevent re-recording.
     *
     * @var array<string, int>
     */
    protected array $replayedFingerprints = [];

    protected bool $interceptorBooted = false;

    public function __construct(
        protected ConfigRepository $config,
        protected CassetteRepositoryInterface $repository,
        protected RedactorInterface $redactor,
        protected HttpFactory $http,
        protected ?EventDispatcher $events = null,
    ) {}

    /**
     * Get or set the operating mode.
     */
    public function mode(Mode|string|null $mode = null): Mode|self
    {
        if ($mode === null) {
            if ($this->overrideMode !== null) {
                return $this->overrideMode;
            }

            $configMode = $this->config->get('http-client-replay.mode', Mode::Auto->value);

            if ($configMode instanceof Mode) {
                return $configMode;
            }

            return Mode::tryFrom((string) $configMode) ?? Mode::Auto;
        }

        $this->overrideMode = is_string($mode) ? (Mode::tryFrom($mode) ?? Mode::Auto) : $mode;

        return $this;
    }

    public function isOff(): bool
    {
        return $this->mode() === Mode::Off;
    }

    public function isRecording(): bool
    {
        return $this->mode() === Mode::Record;
    }

    public function isReplaying(): bool
    {
        return $this->mode() === Mode::Replay;
    }

    public function isAuto(): bool
    {
        return $this->mode() === Mode::Auto;
    }

    /**
     * Set the active cassette name or execute a callback within an active cassette.
     */
    public function useCassette(string $name, ?callable $callback = null, DateTimeInterface|DateInterval|int|string|null $ttl = null): mixed
    {
        if ($callback === null) {
            $this->activeCassette = $name;

            if ($ttl !== null) {
                $this->runtimeTtl = $ttl;
            }

            return $this;
        }

        $previous = $this->activeCassette;
        $previousTtl = $this->runtimeTtl;

        $this->activeCassette = $name;

        if ($ttl !== null) {
            $this->runtimeTtl = $ttl;
        }

        try {
            return $callback();
        } finally {
            $this->activeCassette = $previous;
            $this->runtimeTtl = $previousTtl;
        }
    }

    /**
     * Remember an HTTP response in a named cassette for a given duration.
     */
    public function remember(string $name, DateTimeInterface|DateInterval|int|string $ttl, callable $callback): mixed
    {
        return $this->useCassette($name, $callback, ttl: $ttl);
    }

    /**
     * Remember an HTTP response in a named cassette indefinitely.
     */
    public function rememberForever(string $name, callable $callback): mixed
    {
        return $this->useCassette($name, $callback, ttl: null);
    }

    /**
     * Set runtime TTL for subsequent requests.
     */
    public function ttl(DateTimeInterface|DateInterval|int|string|null $ttl): self
    {
        $this->runtimeTtl = $ttl;

        return $this;
    }

    /**
     * Mark subsequent requests to never expire.
     */
    public function forever(): self
    {
        $this->runtimeTtl = null;

        return $this;
    }

    /**
     * Execute a callback with a specific TTL context.
     */
    public function withTtl(DateTimeInterface|DateInterval|int|string|null $ttl, callable $callback): mixed
    {
        $previous = $this->runtimeTtl;
        $this->runtimeTtl = $ttl;

        try {
            return $callback();
        } finally {
            $this->runtimeTtl = $previous;
        }
    }

    /**
     * Touch a cassette to refresh its recorded timestamp and optionally set a new TTL.
     */
    public function touch(string $identifier, DateTimeInterface|DateInterval|int|string|null $ttl = null): bool
    {
        $cassette = $this->repository->find($identifier);

        if ($cassette === null) {
            return false;
        }

        $cassette['recorded_at'] = now()->toIso8601String();

        if ($ttl !== null) {
            $cassette['ttl'] = $this->getSeconds($ttl);
        }

        $this->repository->store($identifier, $cassette);

        return true;
    }

    /**
     * Get the active cassette identifier if set.
     */
    public function activeCassette(): ?string
    {
        return $this->activeCassette;
    }

    /**
     * Execute a callback in record mode.
     */
    public function record(callable $callback): mixed
    {
        $previous = $this->overrideMode;
        $this->overrideMode = Mode::Record;

        try {
            return $callback();
        } finally {
            $this->overrideMode = $previous;
        }
    }

    /**
     * Execute a callback in replay mode.
     */
    public function replay(callable $callback): mixed
    {
        $previous = $this->overrideMode;
        $this->overrideMode = Mode::Replay;

        try {
            return $callback();
        } finally {
            $this->overrideMode = $previous;
        }
    }

    /**
     * Helper for replay mode or setting mode to replay.
     */
    public function fake(?callable $callback = null): mixed
    {
        if ($callback !== null) {
            return $this->replay($callback);
        }

        return $this->mode(Mode::Replay);
    }

    /**
     * Set scoped URL patterns to handle.
     *
     * @param  array<array-key, mixed>|string  $patterns
     * @param  array<string, mixed>  $options
     */
    public function scope(array|string $patterns, array $options = []): self
    {
        if (is_string($patterns)) {
            $this->runtimeScopes = ! empty($options)
                ? [$patterns => $options]
                : [$patterns];
        } else {
            $this->runtimeScopes = $patterns;
        }

        return $this;
    }

    /**
     * Set ignored URL patterns that should pass through live.
     *
     * @param  array<int, string>|string  $patterns
     */
    public function ignore(array|string $patterns): self
    {
        $this->runtimeIgnore = (array) $patterns;

        return $this;
    }

    /**
     * Determine if a request should be intercepted based on scopes and ignore rules.
     */
    public function shouldHandle(Request $request): bool
    {
        $url = $request->url();

        $ignorePatterns = $this->runtimeIgnore ?? (array) $this->config->get('http-client-replay.ignore', []);

        foreach ($ignorePatterns as $pattern) {
            if ($this->urlMatches($pattern, $url)) {
                return false;
            }
        }

        $scopePatterns = $this->runtimeScopes ?? (array) $this->config->get('http-client-replay.scopes', []);

        if (empty($scopePatterns)) {
            return true;
        }

        foreach ($scopePatterns as $key => $value) {
            $pattern = is_string($key) ? $key : (string) $value;

            if ($this->urlMatches($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve configured options for the scope matching the given URL.
     *
     * @return array<string, mixed>|null
     */
    public function getScopeOptions(string $url): ?array
    {
        $scopePatterns = $this->runtimeScopes ?? (array) $this->config->get('http-client-replay.scopes', []);

        foreach ($scopePatterns as $key => $value) {
            $pattern = is_string($key) ? $key : (string) $value;

            if ($this->urlMatches($pattern, $url)) {
                return is_array($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * Calculate seconds from a DateTimeInterface, DateInterval, integer, or numeric string.
     */
    public function getSeconds(DateTimeInterface|DateInterval|int|string|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return $ttl;
        }

        if (is_string($ttl)) {
            return is_numeric($ttl) ? (int) $ttl : null;
        }

        $duration = $this->parseDateInterval($ttl);

        if ($duration instanceof DateTimeInterface) {
            $duration = (int) ceil(
                Carbon::now()->diffInMilliseconds($duration, false) / 1000,
            );
        }

        return (int) ($duration > 0 ? $duration : 0);
    }

    /**
     * Calculate the age in seconds of a cassette.
     *
     * @param  array<string, mixed>  $cassette
     */
    public function getCassetteAge(array $cassette): int
    {
        $recordedAt = isset($cassette['recorded_at'])
            ? Carbon::parse((string) $cassette['recorded_at'])->getTimestamp()
            : 0;

        return max(0, now()->getTimestamp() - $recordedAt);
    }

    /**
     * Resolve the applicable TTL (in seconds) for a cassette request.
     *
     * Hierarchy:
     * 1. Runtime override (ttl(), withTtl(), useCassette(..., ttl: ...))
     * 2. Request options ('replay_ttl' in Guzzle options or 'X-Replay-TTL' header)
     * 3. Cassette-level TTL ($cassette['ttl'])
     * 4. Scope-level TTL (matching URL pattern options['ttl'])
     * 5. Driver-level TTL (config drivers.{driver}.ttl)
     * 6. Global default TTL (config ttl)
     *
     * @param  array<string, mixed>  $cassette
     * @param  array<string, mixed>  $options
     */
    public function resolveTtl(Request $request, string $identifier, array $cassette = [], array $options = []): ?int
    {
        if ($this->runtimeTtl !== false) {
            return $this->runtimeTtl === null ? null : $this->getSeconds($this->runtimeTtl);
        }

        if (isset($options['replay_ttl'])) {
            return $this->getSeconds($options['replay_ttl']);
        }

        if ($request->hasHeader('X-Replay-TTL')) {
            $header = $request->header('X-Replay-TTL');
            $ttlHeader = $header[0] ?? null;

            if ($ttlHeader !== null) {
                return (int) $ttlHeader;
            }
        }

        if (isset($cassette['ttl'])) {
            return (int) $cassette['ttl'];
        }

        $scopeOptions = $this->getScopeOptions($request->url());

        if (isset($scopeOptions['ttl'])) {
            return $this->getSeconds($scopeOptions['ttl']);
        }

        $driver = (string) $this->config->get('http-client-replay.driver', 'file');
        $driverTtl = $this->config->get("http-client-replay.drivers.{$driver}.ttl");

        if ($driverTtl !== null) {
            return $this->getSeconds($driverTtl);
        }

        $globalTtl = $this->config->get('http-client-replay.ttl');

        if ($globalTtl !== null) {
            return $this->getSeconds($globalTtl);
        }

        return null;
    }

    /**
     * Determine if a cassette is expired based on resolved TTL and recorded timestamp.
     *
     * @param  array<string, mixed>  $cassette
     * @param  array<string, mixed>  $options
     */
    public function isExpired(array $cassette, Request $request, array $options = [], ?string $identifier = null): bool
    {
        $identifier ??= (string) ($cassette['identifier'] ?? '');
        $ttl = $this->resolveTtl($request, $identifier, $cassette, $options);

        if ($ttl === null) {
            return false;
        }

        if ($ttl <= 0) {
            return true;
        }

        $recordedAt = isset($cassette['recorded_at'])
            ? Carbon::parse((string) $cassette['recorded_at'])->getTimestamp()
            : 0;

        if ($recordedAt <= 0) {
            return false;
        }

        $age = now()->getTimestamp() - $recordedAt;

        return $age >= $ttl;
    }

    /**
     * Determine if a cassette data array is expired without an active Request.
     *
     * @param  array<string, mixed>  $cassette
     */
    public function isExpiredCassetteData(array $cassette): bool
    {
        $url = (string) ($cassette['request']['url'] ?? '');

        if (isset($cassette['ttl'])) {
            $ttl = (int) $cassette['ttl'];
        } else {
            $scopeOptions = $url !== '' ? $this->getScopeOptions($url) : null;

            if (isset($scopeOptions['ttl'])) {
                $ttl = $this->getSeconds($scopeOptions['ttl']);
            } else {
                $driver = (string) $this->config->get('http-client-replay.driver', 'file');
                $driverTtl = $this->config->get("http-client-replay.drivers.{$driver}.ttl");

                if ($driverTtl !== null) {
                    $ttl = $this->getSeconds($driverTtl);
                } else {
                    $globalTtl = $this->config->get('http-client-replay.ttl');
                    $ttl = $globalTtl !== null ? $this->getSeconds($globalTtl) : null;
                }
            }
        }

        if ($ttl === null) {
            return false;
        }

        if ($ttl <= 0) {
            return true;
        }

        $age = $this->getCassetteAge($cassette);

        return $age >= $ttl;
    }

    /**
     * Intercept outbound HTTP requests via Http::fake().
     *
     * @param  array<string, mixed>  $options
     */
    public function handleOutboundRequest(Request $request, array $options = []): ?PromiseInterface
    {
        if ($this->isOff() || ! $this->shouldHandle($request)) {
            return null;
        }

        if ($this->isRecording()) {
            return null;
        }

        $fingerprint = RequestFingerprint::fromRequest($request);
        $identifier = $this->activeCassette ?? $fingerprint->defaultIdentifier();

        $cassette = $this->repository->find($identifier);

        if ($cassette !== null) {
            if ($this->isExpired($cassette, $request, $options, $identifier)) {
                $this->repository->delete($identifier);

                $age = $this->getCassetteAge($cassette);
                $ttl = (int) $this->resolveTtl($request, $identifier, $cassette, $options);

                $this->eventDispatcher()?->dispatch(
                    new CassetteExpired($identifier, $request, $cassette, $age, $ttl),
                );

                if ($this->isReplaying()) {
                    throw new CassetteExpiredException(
                        $request->method(),
                        $request->url(),
                        $identifier,
                        $age,
                        $ttl,
                    );
                }

                // In AUTO mode with expired cassette: return null so live network request is executed
                return null;
            }

            $this->markAsReplayed($fingerprint->hash());

            /** @var array<string, mixed> $responseData */
            $responseData = $cassette['response'] ?? [];
            /** @var string|null $body */
            $body = $responseData['body'] ?? null;
            /** @var int $status */
            $status = (int) ($responseData['status'] ?? 200);
            /** @var array<string, mixed> $headers */
            $headers = (array) ($responseData['headers'] ?? []);

            return $this->http::response($body, $status, $headers);
        }

        if ($this->isReplaying()) {
            throw new CassetteNotFoundException($request->method(), $request->url(), $identifier);
        }

        // In AUTO mode with missing cassette: return null so live network request is executed
        return null;
    }

    /**
     * Intercept completed HTTP responses and record cassettes.
     */
    public function handleResponseReceived(ResponseReceived $event): void
    {
        if ($this->isOff() || ! $this->shouldHandle($event->request)) {
            return;
        }

        $fingerprint = RequestFingerprint::fromRequest($event->request);

        // If this response was replayed from cassette, do not re-record
        if ($this->consumeReplayed($fingerprint->hash())) {
            return;
        }

        if ($this->isReplaying()) {
            return;
        }

        $identifier = $this->activeCassette ?? $fingerprint->defaultIdentifier();

        $cassetteData = [
            'identifier' => $identifier,
            'recorded_at' => now()->toIso8601String(),
            'signature' => $fingerprint->signature(),
            'fingerprint' => $fingerprint->hash(),
            'request' => [
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'headers' => $event->request->headers(),
                'body' => $event->request->body(),
            ],
            'response' => [
                'status' => $event->response->status(),
                'headers' => $event->response->headers(),
                'body' => $event->response->body(),
            ],
        ];

        /** @var array<string, mixed> $redactedRequest */
        $redactedRequest = $this->redactor->redactRequest($cassetteData['request']);
        /** @var array<string, mixed> $redactedResponse */
        $redactedResponse = $this->redactor->redactResponse($cassetteData['response']);

        $cassetteData['request'] = $redactedRequest;
        $cassetteData['response'] = $redactedResponse;

        $this->repository->store($identifier, $cassetteData);

        $this->eventDispatcher()?->dispatch(
            new CassetteRecorded($identifier, $event->request, $event->response, $cassetteData),
        );
    }

    protected function eventDispatcher(): ?EventDispatcher
    {
        if (function_exists('app') && app()->bound('events')) {
            /** @var EventDispatcher $events */
            $events = app()->make(EventDispatcher::class);

            return $events;
        }

        return $this->events;
    }

    /**
     * Register the global Http::fake() interceptor.
     */
    public function bootInterceptor(): void
    {
        if ($this->interceptorBooted) {
            return;
        }

        $this->http->fake(function (Request $request, array $options): ?PromiseInterface {
            return $this->handleOutboundRequest($request, $options);
        });

        $this->interceptorBooted = true;
    }

    /**
     * Reset runtime overrides and in-memory replay tracking.
     */
    public function reset(): void
    {
        $this->activeCassette = null;
        $this->overrideMode = null;
        $this->runtimeScopes = null;
        $this->runtimeIgnore = null;
        $this->replayedFingerprints = [];
        $this->runtimeTtl = false;
    }

    public function repository(): CassetteRepositoryInterface
    {
        return $this->repository;
    }

    public function redactor(): RedactorInterface
    {
        return $this->redactor;
    }

    protected function markAsReplayed(string $fingerprintHash): void
    {
        $this->replayedFingerprints[$fingerprintHash] = ($this->replayedFingerprints[$fingerprintHash] ?? 0) + 1;
    }

    protected function consumeReplayed(string $fingerprintHash): bool
    {
        if (! isset($this->replayedFingerprints[$fingerprintHash]) || $this->replayedFingerprints[$fingerprintHash] <= 0) {
            return false;
        }

        $this->replayedFingerprints[$fingerprintHash]--;

        if ($this->replayedFingerprints[$fingerprintHash] <= 0) {
            unset($this->replayedFingerprints[$fingerprintHash]);
        }

        return true;
    }

    protected function urlMatches(string $pattern, string $url): bool
    {
        if (str_starts_with($pattern, '/') || str_starts_with($pattern, '#')) {
            return (bool) preg_match($pattern, $url);
        }

        if (Str::is($pattern, $url)) {
            return true;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return Str::is($pattern, $host);
    }
}
