<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Events;

use Illuminate\Http\Client\Request;

class CassetteExpired
{
    /**
     * @param  array<string, mixed>  $cassetteData
     */
    public function __construct(
        public readonly string $identifier,
        public readonly Request $request,
        public readonly array $cassetteData,
        public readonly int $age,
        public readonly int $ttl,
    ) {}
}
