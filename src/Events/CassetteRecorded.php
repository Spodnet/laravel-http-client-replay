<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Events;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;

class CassetteRecorded
{
    /**
     * @param  array<string, mixed>  $cassetteData
     */
    public function __construct(
        public readonly string $identifier,
        public readonly Request $request,
        public readonly Response $response,
        public readonly array $cassetteData,
    ) {}
}
