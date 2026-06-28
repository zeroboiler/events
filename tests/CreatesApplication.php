<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Illuminate\Events\Dispatcher;
use Illuminate\Hashing\HashManager;
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

            public function configPath(string $path = ''): string
            {
                return __DIR__ . '/../config' . ($path !== '' ? '/' . $path : '');
            }

            public function databasePath(string $path = ''): string
            {
                return __DIR__ . '/../database' . ($path !== '' ? '/' . $path : '');
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

        // Bind core services
        $app->singleton('events', fn (): Dispatcher => new Dispatcher($app));
        $app->singleton('queue', fn (): QueueManager => new QueueManager($app));
        $app->singleton('hash', fn (): HashManager => new HashManager($app));

        // Bind log service so Log facade works in tests
        $app->singleton('log', fn (): LogManager => new LogManager($app));

        // Bind Schema facade - get grammar from the connection
        $app->instance('db.schema', $db->connection()->getSchemaBuilder());
        $app->alias('db.schema', Builder::class);

        // Bind Faker for factories
        $app->singleton(Generator::class, fn () => Factory::create('en_US'));
        $app->alias(Generator::class, 'faker');

        // Create a simple config repository and bind it
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
