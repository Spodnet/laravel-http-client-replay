<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Spodnet\HttpClientReplay\Matchers\RequestFingerprint;

it('generates consistent fingerprints regardless of query parameter ordering', function () {
    $psrReq1 = new PsrRequest('GET', 'https://api.example.com/items?b=2&a=1&c=3');
    $psrReq2 = new PsrRequest('GET', 'https://api.example.com/items?c=3&a=1&b=2');

    $fp1 = RequestFingerprint::fromRequest(new Request($psrReq1));
    $fp2 = RequestFingerprint::fromRequest(new Request($psrReq2));

    expect($fp1->hash())->toBe($fp2->hash())
        ->and($fp1->signature())->toBe($fp2->signature());
});

it('generates consistent fingerprints for JSON payloads with different key order', function () {
    $psrReq1 = new PsrRequest(
        'POST',
        'https://api.example.com/items',
        ['Content-Type' => 'application/json'],
        json_encode(['z' => 10, 'a' => 20]),
    );
    $psrReq2 = new PsrRequest(
        'POST',
        'https://api.example.com/items',
        ['Content-Type' => 'application/json'],
        json_encode(['a' => 20, 'z' => 10]),
    );

    $fp1 = RequestFingerprint::fromRequest(new Request($psrReq1));
    $fp2 = RequestFingerprint::fromRequest(new Request($psrReq2));

    expect($fp1->bodyHash())->toBe($fp2->bodyHash())
        ->and($fp1->hash())->toBe($fp2->hash());
});

it('derives default identifier with host and slug', function () {
    $psrReq = new PsrRequest('GET', 'https://api.strava.com/v3/athlete/activities?page=1');
    $fp = RequestFingerprint::fromRequest(new Request($psrReq));

    $identifier = $fp->defaultIdentifier();

    expect($identifier)->toStartWith('api.strava.com/GET_v3_athlete_activities_');
});
