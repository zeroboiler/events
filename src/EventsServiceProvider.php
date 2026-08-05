<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

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

class EventsServiceProvider extends ServiceProvider
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
        $this->app->singleton(EventManager::class);

        // Register SubscriptionBuilder as a transient (not shared) service
        $this->app->bind(SubscriptionBuilder::class);
    }

    #[\Override]
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/events.php' => config_path('events.php'),
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
