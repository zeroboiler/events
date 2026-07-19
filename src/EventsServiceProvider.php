<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use IlluminateSupport\ServiceProvider;
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
use ZeroBoiler\Events\Domain\DomainEventDispatcher;

class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/events.php',
            'events'
        );

        $this->app->singleton(ConditionEngine::class);
        $this->app->singleton(ActionResolver::class);
        $this->app->singleton(EventManager::class);

        // Register DomainEventDispatcher — domain event support
        $this->app->singleton(DomainEventDispatcher::class, function (): DomainEventDispatcher {
            $laravelDispatcher = $this->app->bound(\Illuminate\Contracts\Events\Dispatcher::class)
                ? $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)
                : null;

            $dispatcher = new DomainEventDispatcher($laravelDispatcher);

            // Wire domain events to fire through EventManager automatically
            $dispatcher->setEventForwarder(function (string $eventType, array $payload): void {
                try {
                    $this->app->make(EventManager::class)->fire($eventType, $payload, 'domain');
                } catch (\Throwable) {
                    // Silently fail — domain events should not break
                    // if the event system has issues.
                }
            });

            return $dispatcher;
        });

        // Register SubscriptionBuilder as a transient (not shared) service
        $this->app->bind(SubscriptionBuilder::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/events.php' => config_path('events.php'),
            ], 'events-config');

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

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
