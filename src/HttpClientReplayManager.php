<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Contracts\RedactorInterface;
use Spodnet\HttpClientReplay\Enums\Mode;
use Spodnet\HttpClientReplay\Events\CassetteRecorded;
use Spodnet\HttpClientReplay\Exceptions\CassetteNotFoundException;
use Spodnet\HttpClientReplay\Matchers\RequestFingerprint;

class HttpClientReplayManager
{
    protected ?string $activeCassette = null;

    protected ?Mode $overrideMode = null;

    /**
     * @var array<int, string>|null
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
    public function useCassette(string $name, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            $this->activeCassette = $name;

            return $this;
        }

        $previous = $this->activeCassette;
        $this->activeCassette = $name;

        try {
            return $callback();
        } finally {
            $this->activeCassette = $previous;
        }
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
     * @param  array<int, string>|string  $patterns
     */
    public function scope(array|string $patterns): self
    {
        $this->runtimeScopes = (array) $patterns;

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

        foreach ($scopePatterns as $pattern) {
            if ($this->urlMatches($pattern, $url)) {
                return true;
            }
        }

        return false;
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

        $this->events?->dispatch(
            new CassetteRecorded($identifier, $event->request, $event->response, $cassetteData),
        );
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
