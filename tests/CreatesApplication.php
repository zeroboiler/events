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

trait CreatesApplication
{
    public function createApplication(): Container
    {
        $basePath = dirname(__DIR__);

        // Create a simple application-like container
        $app = new class extends Container
        {
            public function runningInConsole(): bool
            {
                return true;
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
        $app->singleton('events', fn () => new Dispatcher($app));
        $app->singleton('queue', fn () => new QueueManager($app));
        $app->singleton('hash', fn () => new HashManager($app));

        // Bind log service so Log facade works in tests
        $app->singleton('log', fn () => new LogManager($app));

        // Bind Schema facade - get grammar from the connection
        $app->instance('db.schema', $db->connection()->getSchemaBuilder());
        $app->alias('db.schema', Builder::class);

        // Bind Faker for factories
        $app->singleton(Faker\Generator::class, fn () => Faker\Factory::create('en_US'));
        $app->alias(Faker\Generator::class, 'faker');

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
