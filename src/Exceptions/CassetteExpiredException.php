<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Exceptions;

class CassetteExpiredException extends CassetteNotFoundException
{
    public function __construct(
        string $method,
        string $url,
        string $expectedIdentifier,
        public readonly int $age,
        public readonly int $ttl,
    ) {
        parent::__construct($method, $url, $expectedIdentifier);

        $this->message = "HTTP Client Replay [replay mode]: Cassette [{$expectedIdentifier}] matching [{$method} {$url}] has expired (age: {$age}s, TTL: {$ttl}s) and live network calls are forbidden in replay mode. Re-record the cassette in 'record' or 'auto' mode.";
    }
}
