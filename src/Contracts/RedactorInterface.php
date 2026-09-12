<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Contracts;

interface RedactorInterface
{
    /**
     * Redact sensitive information from recorded request data.
     *
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    public function redactRequest(array $requestData): array;

    /**
     * Redact sensitive information from recorded response data.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    public function redactResponse(array $responseData): array;
}
