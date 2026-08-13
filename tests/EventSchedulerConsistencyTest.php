<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;

describe('EventScheduler — resolveEventManager() consistency', function (): void {
    it('resolveEventManager() returns EventManager when bound', function (): void {
        $app = app();

        $scheduler = new EventScheduler($app);

        // Use reflection to call the protected method
        $method = new \ReflectionMethod($scheduler, 'resolveEventManager');
        $result = $method->invoke($scheduler);

        expect($result)->toBeInstanceOf(EventManager::class);
    });

    it('resolveEventManager() returns null when EventManager is not bound', function (): void {
        $app = new \Illuminate\Container\Container;
        // Don't bind EventManager — only set up config

        $config = new \Illuminate\Config\Repository([
            'events' => [
                'table_names' => ['triggers' => 'triggers'],
            ],
        ]);
        $app->instance('config', $config);

        $scheduler = new EventScheduler($app);

        $method = new \ReflectionMethod($scheduler, 'resolveEventManager');
        $result = $method->invoke($scheduler);

        expect($result)->toBeNull();
    });

    it('resolveEventManager() returns null when wrong type is bound', function (): void {
        $app = new \Illuminate\Container\Container;

        $config = new \Illuminate\Config\Repository([
            'events' => [
                'table_names' => ['triggers' => 'triggers'],
            ],
        ]);
        $app->instance('config', $config);
        // Bind a wrong type to EventManager
        $app->instance(EventManager::class, new \stdClass);

        $scheduler = new EventScheduler($app);

        $method = new \ReflectionMethod($scheduler, 'resolveEventManager');
        $result = $method->invoke($scheduler);

        expect($result)->toBeNull();
    });

    it('register() adds both scheduled tasks without errors', function (): void {
        $app = app();
        $config = $app->get('config');

        // Ensure retention config is set
        $config->set('events.retention.days', 30);
        $config->set('events.subscriptions.cleanup_cron', '0 3 * * *');

        $scheduler = new EventScheduler($app);
        $schedule = new Schedule;

        // Should not throw
        $scheduler->register($schedule);

        $events = $schedule->events();

        expect($events)->toHaveCount(2);

        $names = array_map(fn ($e) => $e->command, $events);

        expect($names)->toContain('zeroboiler:events:purge-logs');
        expect($names)->toContain('zeroboiler:events:cleanup-subscriptions');
    });

    it('register() skips log purge when retention days is null', function (): void {
        $app = app();
        $config = $app->get('config');

        $config->set('events.retention.days', null);

        $scheduler = new EventScheduler($app);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        // Only cleanup subscription should be registered
        expect($events)->toHaveCount(1);
        expect($events[0]->command)->toBe('zeroboiler:events:cleanup-subscriptions');
    });

    it('register() skips log purge when retention days is 0', function (): void {
        $app = app();
        $config = $app->get('config');

        $config->set('events.retention.days', 0);

        $scheduler = new EventScheduler($app);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        expect($events)->toHaveCount(1);
    });

    it('register() skips log purge when retention days is negative', function (): void {
        $app = app();
        $config = $app->get('config');

        $config->set('events.retention.days', -5);

        $scheduler = new EventScheduler($app);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        expect($events)->toHaveCount(1);
    });
});

describe('EventScheduler — config edge cases', function (): void {
    it('uses default cron expression when schedule_cron is empty string', function (): void {
        $app = app();
        $config = $app->get('config');

        $config->set('events.retention.days', 30);
        $config->set('events.retention.schedule_cron', '');

        $scheduler = new EventScheduler($app);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        // Should still register both tasks
        expect($events)->toHaveCount(2);
    });

    it('uses default cron expression when cleanup_cron is non-string', function (): void {
        $app = app();
        $config = $app->get('config');

        $config->set('events.retention.days', 30);
        $config->set('events.subscriptions.cleanup_cron', 12345);

        $scheduler = new EventScheduler($app);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        expect($events)->toHaveCount(2);
    });
});
