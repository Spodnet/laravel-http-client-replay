<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Matchers;

use Illuminate\Http\Client\Request;
use JsonException;

class RequestFingerprint
{
    protected string $method;

    protected string $normalizedPath;

    protected string $sortedQuery;

    protected string $bodyHash;

    protected string $host;

    public function __construct(Request $request)
    {
        $this->method = strtoupper($request->method());

        $parsedUrl = parse_url($request->url());
        $scheme = strtolower($parsedUrl['scheme'] ?? 'https');
        $this->host = strtolower($parsedUrl['host'] ?? 'localhost');
        $port = isset($parsedUrl['port']) ? ':'.$parsedUrl['port'] : '';
        $path = $parsedUrl['path'] ?? '/';

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $this->normalizedPath = "{$scheme}://{$this->host}{$port}{$path}";
        $this->sortedQuery = $this->normalizeQuery($parsedUrl['query'] ?? '');
        $this->bodyHash = $this->calculateBodyHash($request->body());
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function normalizedPath(): string
    {
        return $this->normalizedPath;
    }

    public function sortedQuery(): string
    {
        return $this->sortedQuery;
    }

    public function bodyHash(): string
    {
        return $this->bodyHash;
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * Get the readable signature of the request.
     */
    public function signature(): string
    {
        $url = $this->normalizedPath;

        if ($this->sortedQuery !== '') {
            $url .= '?'.$this->sortedQuery;
        }

        $bodyPart = $this->bodyHash !== '' ? " [body:{$this->bodyHash}]" : '';

        return "{$this->method} {$url}{$bodyPart}";
    }

    /**
     * Compute a deterministic SHA-256 hash for matching.
     */
    public function hash(): string
    {
        return hash('sha256', $this->signature());
    }

    /**
     * Generate a default cassette identifier based on domain and request signature.
     */
    public function defaultIdentifier(): string
    {
        $cleanPath = trim((string) parse_url($this->normalizedPath, PHP_URL_PATH), '/');
        $slug = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $cleanPath) ?: 'root';
        $shortHash = substr($this->hash(), 0, 8);

        return "{$this->host}/{$this->method}_{$slug}_{$shortHash}";
    }

    /**
     * Normalize and sort query string parameters.
     */
    protected function normalizeQuery(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }

        parse_str($queryString, $params);

        if (empty($params)) {
            return '';
        }

        $params = $this->recursiveSort($params);

        return http_build_query($params);
    }

    /**
     * Recursively sort array keys.
     *
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, mixed>
     */
    protected function recursiveSort(array $array): array
    {
        ksort($array);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                /** @var array<array-key, mixed> $value */
                $array[$key] = $this->recursiveSort($value);
            }
        }

        return $array;
    }

    /**
     * Calculate hash of the request body (normalized for JSON payloads).
     */
    protected function calculateBodyHash(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '';
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $decoded = $this->recursiveSort($decoded);

                return hash('sha256', (string) json_encode($decoded, JSON_UNESCAPED_SLASHES));
            }
        } catch (JsonException) {
            // Not a JSON body, fall through to raw string hash
        }

        return hash('sha256', $trimmed);
    }
}
