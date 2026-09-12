<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spodnet\HttpClientReplay\Repositories\CassetteRepositoryManager;
use Spodnet\HttpClientReplay\Repositories\DatabaseCassetteRepository;
use Spodnet\HttpClientReplay\Repositories\JsonFileCassetteRepository;
use Spodnet\HttpClientReplay\Repositories\StorageDiskCassetteRepository;

it('stores and retrieves cassettes using JsonFileCassetteRepository', function () {
    $tempDir = sys_get_temp_dir().'/http-client-replay-driver-'.bin2hex(random_bytes(6));
    $repo = new JsonFileCassetteRepository($tempDir);

    $data = [
        'identifier' => 'api/test',
        'signature' => 'GET https://api.test/resource',
        'response' => ['status' => 200, 'body' => '{"ok":true}'],
    ];

    $repo->store('api/test', $data);

    expect($repo->has('api/test'))->toBeTrue()
        ->and($repo->find('api/test'))->toBe($data);

    $all = $repo->all();
    expect($all)->toHaveCount(1)
        ->and($all[0]['identifier'])->toBe('api/test')
        ->and($all[0]['signature'])->toBe('GET https://api.test/resource');

    expect($repo->delete('api/test'))->toBeTrue()
        ->and($repo->has('api/test'))->toBeFalse();
});

it('stores and retrieves cassettes using StorageDiskCassetteRepository', function () {
    Storage::fake('cassettes');
    $repo = new StorageDiskCassetteRepository(Storage::disk('cassettes'), 'testing/cassettes');

    $data = [
        'identifier' => 'stripe/charge',
        'signature' => 'POST https://api.stripe.com/v1/charges',
        'response' => ['status' => 200, 'body' => '{"id":"ch_123"}'],
    ];

    $repo->store('stripe/charge', $data);

    expect($repo->has('stripe/charge'))->toBeTrue()
        ->and($repo->find('stripe/charge'))->toBe($data);

    $all = $repo->all();
    expect($all)->toHaveCount(1)
        ->and($all[0]['identifier'])->toBe('stripe/charge');

    expect($repo->clear('stripe'))->toBe(1)
        ->and($repo->has('stripe/charge'))->toBeFalse();
});

it('stores and retrieves cassettes using DatabaseCassetteRepository', function () {
    Schema::create('http_client_replay_cassettes', function (Blueprint $table) {
        $table->id();
        $table->string('identifier')->unique();
        $table->string('domain')->nullable()->index();
        $table->string('signature')->index();
        $table->unsignedSmallInteger('status')->default(200);
        $table->longText('data');
        $table->timestamps();
    });

    $repo = new DatabaseCassetteRepository(DB::connection(), 'http_client_replay_cassettes');

    $data = [
        'identifier' => 'openai/models',
        'signature' => 'GET https://api.openai.com/v1/models',
        'response' => ['status' => 200, 'body' => '{"models":[]}'],
    ];

    $repo->store('openai/models', $data);

    expect($repo->has('openai/models'))->toBeTrue()
        ->and($repo->find('openai/models'))->toBe($data);

    $all = $repo->all();
    expect($all)->toHaveCount(1)
        ->and($all[0]['identifier'])->toBe('openai/models')
        ->and($all[0]['status'])->toBe(200);

    expect($repo->clear('openai'))->toBe(1)
        ->and($repo->has('openai/models'))->toBeFalse();
});

it('resolves configured storage driver through CassetteRepositoryManager', function () {
    config()->set('http-client-replay.driver', 'file');

    /** @var CassetteRepositoryManager $manager */
    $manager = app(CassetteRepositoryManager::class);

    expect($manager->getDefaultDriver())->toBe('file');
});
