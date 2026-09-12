<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Spodnet\HttpClientReplay\Commands\ClearCassettesCommand;
use Spodnet\HttpClientReplay\Commands\ListCassettesCommand;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;
use Spodnet\HttpClientReplay\Contracts\RedactorInterface;
use Spodnet\HttpClientReplay\Enums\Mode;
use Spodnet\HttpClientReplay\Redactors\HeaderAndBodyRedactor;
use Spodnet\HttpClientReplay\Repositories\CassetteRepositoryManager;

class HttpClientReplayServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/http-client-replay.php', 'http-client-replay');

        $this->app->singleton(CassetteRepositoryManager::class, function (Application $app): CassetteRepositoryManager {
            return new CassetteRepositoryManager($app);
        });

        $this->app->alias(CassetteRepositoryManager::class, CassetteRepositoryInterface::class);

        $this->app->singleton(RedactorInterface::class, function (Application $app): HeaderAndBodyRedactor {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            /** @var array<string, mixed> $redactionConfig */
            $redactionConfig = (array) $config->get('http-client-replay.redaction', []);

            return new HeaderAndBodyRedactor($redactionConfig);
        });

        $this->app->singleton(HttpClientReplayManager::class, function (Application $app): HttpClientReplayManager {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            /** @var CassetteRepositoryInterface $repository */
            $repository = $app->make(CassetteRepositoryInterface::class);
            /** @var RedactorInterface $redactor */
            $redactor = $app->make(RedactorInterface::class);
            /** @var HttpFactory $http */
            $http = $app->make(HttpFactory::class);
            /** @var EventDispatcher $events */
            $events = $app->make(EventDispatcher::class);

            return new HttpClientReplayManager(
                $config,
                $repository,
                $redactor,
                $http,
                $events,
            );
        });

        $this->app->alias(HttpClientReplayManager::class, 'http-client-replay');
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        /** @var EventDispatcher $events */
        $events = $this->app->make('events');
        $events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            /** @var HttpClientReplayManager $manager */
            $manager = $this->app->make(HttpClientReplayManager::class);
            $manager->handleResponseReceived($event);
        });

        /** @var ConfigRepository $config */
        $config = $this->app->make('config');
        $mode = (string) $config->get('http-client-replay.mode', Mode::Auto->value);

        if ($mode !== Mode::Off->value) {
            /** @var HttpClientReplayManager $manager */
            $manager = $this->app->make(HttpClientReplayManager::class);
            $manager->bootInterceptor();
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/http-client-replay.php' => config_path('http-client-replay.php'),
        ], ['http-client-replay', 'http-client-replay-config', 'laravel-http-client-replay', 'laravel-http-client-replay-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['http-client-replay', 'http-client-replay-migrations', 'laravel-http-client-replay', 'laravel-http-client-replay-migrations']);

        $this->commands([
            ListCassettesCommand::class,
            ClearCassettesCommand::class,
        ]);
    }
}
