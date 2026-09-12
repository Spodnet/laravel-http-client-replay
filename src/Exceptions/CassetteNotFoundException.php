<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Exceptions;

use RuntimeException;

class CassetteNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly string $expectedIdentifier,
    ) {
        parent::__construct(
            "HTTP Client Replay [replay mode]: No cassette found matching [{$method} {$url}] (expected identifier: '{$expectedIdentifier}'). Run your test or operation in 'record' or 'auto' mode first to capture the cassette.",
        );
    }
}
