<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;

final class EventsServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/events.php',
            'events'
        );

        $this->app->singleton(ConditionEngine::class);
        $this->app->singleton(ActionResolver::class);
        $this->app->singleton(EventManager::class, function (Container $app): EventManager {
            return new EventManager(
                $app->make(ConditionEngine::class),
                $app->make(ActionResolver::class),
                $app,
            );
        });

        // Register SubscriptionBuilder as a transient (not shared) service
        $this->app->bind(SubscriptionBuilder::class);
    }

    #[\Override]
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $configPath = $this->app->configPath('events.php');
            $this->publishes([
                __DIR__.'/../config/events.php' => $configPath,
            ], 'events-config');

            $this->commands([
                EventsListCommand::class,
                EventsRegisterCommand::class,
                EventsFireCommand::class,
                EventsLogCommand::class,
                EventsRetryCommand::class,
                EventsEnableCommand::class,
                EventsDisableCommand::class,
                // Subscription commands
                EventsSubscribeCommand::class,
                EventsUnsubscribeCommand::class,
                EventsSubscriptionsCommand::class,
                EventsRedeliverCommand::class,
            ]);
        }
    }
}
