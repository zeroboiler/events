<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;

describe('EventManager::registerScheduler', function (): void {
    it('delegates to EventScheduler::register() successfully', function (): void {
        $app = $this->app;

        // Ensure EventManager is resolved from the container
        $eventManager = $app->make(EventManager::class);
        expect($eventManager)->toBeInstanceOf(EventManager::class);

        // Create a real Schedule instance
        $schedule = new Schedule($app);

        // registerScheduler should not throw
        $eventManager->registerScheduler($schedule);

        // Verify the scheduled tasks were registered
        $events = $schedule->events();
        expect($events)->toHaveCount(2);

        $names = array_map(fn ($e) => $e->command ?? $e->description, $events);
        expect($names)->toContain('zeroboiler:events:purge-logs');
        expect($names)->toContain('zeroboiler:events:cleanup-subscriptions');
    });

    it('throws RuntimeException when EventScheduler cannot be resolved from container', function (): void {
        $app = new Container;
        $app->bind(
            EventManager::class,
            fn (): EventManager => new \ZeroBoiler\Events\EventManager(
                $app->make(\ZeroBoiler\Events\ConditionEngine::class),
                $app->make(\ZeroBoiler\Events\ActionResolver::class),
                $app,
            ),
        );

        // Bind EventScheduler to a non-EventScheduler class
        $app->singleton(\ZeroBoiler\Events\EventScheduler::class, fn (): \stdClass => new \stdClass);

        $eventManager = $app->make(EventManager::class);
        $schedule = new Schedule($app);

        expect(fn () => $eventManager->registerScheduler($schedule))
            ->toThrow(\RuntimeException::class, 'EventScheduler could not be resolved from the container');
    });
});
