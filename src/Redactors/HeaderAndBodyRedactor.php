<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Redactors;

use JsonException;
use Spodnet\HttpClientReplay\Contracts\RedactorInterface;

class HeaderAndBodyRedactor implements RedactorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = [],
    ) {}

    /**
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    public function redactRequest(array $requestData): array
    {
        if (isset($requestData['headers']) && is_array($requestData['headers'])) {
            $requestData['headers'] = $this->redactHeaders($requestData['headers']);
        }

        if (isset($requestData['url']) && is_string($requestData['url'])) {
            $requestData['url'] = $this->redactUrl($requestData['url']);
        }

        if (isset($requestData['body'])) {
            $requestData['body'] = $this->redactBody($requestData['body']);
        }

        return $requestData;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    public function redactResponse(array $responseData): array
    {
        if (isset($responseData['headers']) && is_array($responseData['headers'])) {
            $responseData['headers'] = $this->redactHeaders($responseData['headers']);
        }

        if (isset($responseData['body'])) {
            $responseData['body'] = $this->redactBody($responseData['body']);
        }

        return $responseData;
    }

    /**
     * Redact sensitive headers.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    protected function redactHeaders(array $headers): array
    {
        $sensitiveHeaders = array_map('strtolower', (array) ($this->config['headers'] ?? [
            'authorization',
            'x-api-key',
            'api-key',
            'x-auth-token',
            'cookie',
            'set-cookie',
        ]));

        $mask = (string) ($this->config['mask'] ?? '[REDACTED]');

        foreach ($headers as $name => $values) {
            $lowerName = strtolower((string) $name);

            if (in_array($lowerName, $sensitiveHeaders, true)) {
                if (is_array($values)) {
                    $headers[$name] = array_map(fn (mixed $val): string => $this->maskHeaderValue($lowerName, (string) $val, $mask), $values);
                } else {
                    $headers[$name] = $this->maskHeaderValue($lowerName, (string) $values, $mask);
                }
            }
        }

        return $headers;
    }

    /**
     * Apply appropriate masking to header value.
     */
    protected function maskHeaderValue(string $headerName, string $value, string $mask): string
    {
        if ($headerName === 'authorization') {
            if (str_starts_with(strtolower($value), 'bearer ')) {
                return 'Bearer '.$mask;
            }

            if (str_starts_with(strtolower($value), 'basic ')) {
                return 'Basic '.$mask;
            }
        }

        return $mask;
    }

    /**
     * Redact sensitive query parameters in a URL string.
     */
    protected function redactUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['query']) || $parts['query'] === '') {
            return $url;
        }

        parse_str($parts['query'], $queryParams);

        if (empty($queryParams)) {
            return $url;
        }

        $sensitiveParams = array_map('strtolower', (array) ($this->config['query_parameters'] ?? [
            'api_key',
            'key',
            'token',
            'secret',
            'access_token',
            'refresh_token',
            'client_secret',
        ]));

        $mask = (string) ($this->config['mask'] ?? '[REDACTED]');

        $redactedParams = $this->redactArrayKeys($queryParams, $sensitiveParams, $mask);
        $newQuery = http_build_query($redactedParams);

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}{$host}{$port}{$path}?{$newQuery}{$fragment}";
    }

    /**
     * Redact sensitive payload body fields (supports array, JSON string, and url-encoded string).
     */
    protected function redactBody(mixed $body): mixed
    {
        $sensitiveFields = array_map('strtolower', (array) ($this->config['body_fields'] ?? [
            'client_secret',
            'refresh_token',
            'password',
            'token',
            'access_token',
            'secret',
            'api_key',
        ]));

        $mask = (string) ($this->config['mask'] ?? '[REDACTED]');

        if (is_array($body)) {
            return $this->redactArrayKeys($body, $sensitiveFields, $mask);
        }

        if (is_string($body) && trim($body) !== '') {
            $trimmed = trim($body);

            try {
                /** @var mixed $decoded */
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    $redacted = $this->redactArrayKeys($decoded, $sensitiveFields, $mask);

                    return json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }
            } catch (JsonException) {
                // Not a valid JSON payload
            }

            // Check if form-urlencoded string (e.g. client_secret=xyz&grant_type=code)
            if (str_contains($trimmed, '=') && ! str_contains($trimmed, "\n") && ! str_contains($trimmed, '<')) {
                parse_str($trimmed, $parsedForm);

                if (! empty($parsedForm)) {
                    $hasSensitive = false;
                    foreach (array_keys($parsedForm) as $k) {
                        if (in_array(strtolower((string) $k), $sensitiveFields, true)) {
                            $hasSensitive = true;

                            break;
                        }
                    }

                    if ($hasSensitive) {
                        $redactedForm = $this->redactArrayKeys($parsedForm, $sensitiveFields, $mask);

                        return http_build_query($redactedForm);
                    }
                }
            }
        }

        return $body;
    }

    /**
     * Recursively redact values whose keys match sensitive list.
     *
     * @param  array<array-key, mixed>  $array
     * @param  array<int, string>  $sensitiveKeys
     * @return array<array-key, mixed>
     */
    protected function redactArrayKeys(array $array, array $sensitiveKeys, string $mask): array
    {
        foreach ($array as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, $sensitiveKeys, true)) {
                $array[$key] = $mask;
            } elseif (is_array($value)) {
                /** @var array<array-key, mixed> $value */
                $array[$key] = $this->redactArrayKeys($value, $sensitiveKeys, $mask);
            }
        }

        return $array;
    }
}
