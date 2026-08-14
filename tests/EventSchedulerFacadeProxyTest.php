<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

describe('EventScheduler facade proxy', function (): void {
    test('registerScheduler delegates to EventScheduler via facade', function (): void {
        $schedule = new Schedule;

        // This should not throw — the facade resolves the real EventManager
        // which resolves EventScheduler and calls register()
        EventManagerFacade::registerScheduler($schedule);

        // Verify the scheduled tasks were registered
        $events = $schedule->events();
        expect($events)->toHaveCount(2);

        $names = array_map(fn ($event) => $event->command, $events);
        // Note: closure events have their name, not a command string
        $eventNames = array_map(fn ($event) => $event->description ?? '', $events);

        expect($eventNames)->toContain('zeroboiler:events:purge-logs');
        expect($eventNames)->toContain('zeroboiler:events:cleanup-subscriptions');
    });

    test('registerScheduler via resolved instance also works', function (): void {
        $app = app();
        $eventManager = $app->make(EventManager::class);
        $schedule = new Schedule;

        $eventManager->registerScheduler($schedule);

        $events = $schedule->events();
        expect($events)->toHaveCount(2);
    });

    test('registerScheduler uses config-driven cron expressions', function (): void {
        // Override config for custom cron
        config(['events.retention.schedule_cron' => '0 4 * * *']);
        config(['events.subscriptions.cleanup_cron' => '0 5 * * *']);

        // Create a fresh EventManager with new config
        $app = app();
        $app->forgetInstance(EventManager::class);
        $app->forgetInstance(EventScheduler::class);

        $eventManager = $app->make(EventManager::class);
        $schedule = new Schedule;

        $eventManager->registerScheduler($schedule);

        $events = $schedule->events();
        expect($events)->toHaveCount(2);

        $eventNames = array_map(fn ($event) => $event->description ?? '', $events);
        expect($eventNames)->toContain('zeroboiler:events:purge-logs');
        expect($eventNames)->toContain('zeroboiler:events:cleanup-subscriptions');
    });
});
