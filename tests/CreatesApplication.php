<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Events\Dispatcher;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Log\LogManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Facade;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Tests\Faker\Factory;
use ZeroBoiler\Events\Tests\Faker\Generator;

trait CreatesApplication
{
    public function createApplication(): Container
    {
        // Create a simple application-like container
        $app = new class extends Container
        {
            public function runningInConsole(): bool
            {
                return true;
            }

            public function runningUnitTests(): bool
            {
                return true;
            }

            public function configPath(string $path = ''): string
            {
                return __DIR__.'/../config'.($path !== '' ? '/'.$path : '');
            }

            public function databasePath(string $path = ''): string
            {
                return __DIR__.'/../database'.($path !== '' ? '/'.$path : '');
            }

            public function storagePath(string $path = ''): string
            {
                return sys_get_temp_dir().'/zb_events_test'.($path !== '' ? '/'.$path : '');
            }
        };

        // Set the application instance globally
        Container::setInstance($app);
        $app->instance('app', $app);
        $app->instance(Container::class, $app);
        $app->instance(Application::class, $app);

        // Set up in-memory SQLite database using Eloquent Capsule
        $capsule = new Capsule($app);
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'default');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Bind database manager
        $db = $capsule->getDatabaseManager();
        $app->instance('db', $db);
        $app->alias('db', DatabaseManager::class);

        // Create and bind config early so other services can access it
        $config = new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                    ],
                ],
            ],
            'queue' => ['default' => 'sync'],
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                    ],
                ],
                'prefix' => 'zb_events_',
            ],
            'events' => [
                'table_names' => [
                    'triggers' => 'triggers',
                    'event_logs' => 'event_logs',
                ],
                'queue' => [
                    'connection' => 'sync',
                    'queue' => 'default',
                ],
                'retry' => [
                    'tries' => 3,
                    'backoff' => '60,300,900',
                ],
            ],
        ]);
        $app->instance('config', $config);

        // Bind core services
        $eventDispatcher = new Dispatcher($app);
        $app->singleton('events', fn (): Dispatcher => $eventDispatcher);
        $app->singleton('queue', fn (): QueueManager => new QueueManager($app));
        $app->singleton('hash', fn (): HashManager => new HashManager($app));

        // Set the event dispatcher on Eloquent so model lifecycle
        // callbacks (creating, saving, etc.) actually fire.
        Model::setEventDispatcher($eventDispatcher);

        // Bind log service so Log facade works in tests
        $app->singleton('log', fn (): LogManager => new LogManager($app));

        // Bind HTTP client so Http facade works in tests
        $httpClientFactory = new HttpClientFactory($app->make('events'));
        $app->singleton('http', fn (): HttpClientFactory => $httpClientFactory);
        $app->alias(HttpClientFactory::class, 'http');
        $app->instance(HttpClientFactory::class, $httpClientFactory);

        // Bind cache service so Cache facade works in tests
        $cacheManager = new CacheManager($app);
        $app->singleton('cache', fn (): CacheManager => $cacheManager);
        $app->alias('cache', \Illuminate\Contracts\Cache\Factory::class);
        $app->instance(\Illuminate\Contracts\Cache\Repository::class, $cacheManager->store());

        // Bind Schema facade - get grammar from the connection
        $app->instance('db.schema', $db->connection()->getSchemaBuilder());
        $app->alias('db.schema', Builder::class);

        // Bind Faker for factories
        $app->singleton(Generator::class, fn () => Factory::create('en_US'));
        $app->alias(Generator::class, 'faker');

        // Boot facades
        Facade::setFacadeApplication($app);

        // Set the global app instance for helpers
        if (function_exists('set_test_app')) {
            set_test_app($app);
        }

        // Load migrations
        $this->loadMigrations($db);

        // Register the package service provider manually (skip boot for tests)
        $provider = new EventsServiceProvider($app);
        $provider->register();

        // Boot the service provider
        $provider->boot();

        return $app;
    }

    protected function loadMigrations(DatabaseManager $db): void
    {
        // Load and run migrations
        $migrationFiles = glob(__DIR__.'/../database/migrations/*.php');

        foreach ($migrationFiles as $migrationFile) {
            $migration = require $migrationFile;
            $migration->up();
        }
    }
}
