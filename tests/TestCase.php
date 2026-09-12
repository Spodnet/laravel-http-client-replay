<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Spodnet\HttpClientReplay\Facades\HttpClientReplay;
use Spodnet\HttpClientReplay\HttpClientReplayServiceProvider;
use Spodnet\HttpClientReplay\Testing\InteractsWithHttpClientReplay;

abstract class TestCase extends Orchestra
{
    use InteractsWithHttpClientReplay;

    protected string $testCassettesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testCassettesPath = sys_get_temp_dir().'/http-client-replay-'.getmypid().'-'.bin2hex(random_bytes(6));

        if (! File::isDirectory($this->testCassettesPath)) {
            File::makeDirectory($this->testCassettesPath, 0755, true, true);
        }

        config()->set('http-client-replay.drivers.file.path', $this->testCassettesPath);
    }

    protected function tearDown(): void
    {
        HttpClientReplay::reset();

        if (isset($this->testCassettesPath) && File::isDirectory($this->testCassettesPath)) {
            File::deleteDirectory($this->testCassettesPath);
        }

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            HttpClientReplayServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'HttpClientReplay' => HttpClientReplay::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
