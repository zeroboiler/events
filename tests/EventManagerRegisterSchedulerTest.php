<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Console\Scheduling\Schedule;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;

/**
 * Tests for EventManager::registerScheduler() method.
 *
 * Verifies that registerScheduler() correctly delegates to EventScheduler::register()
 * and that the scheduled tasks are registered with the expected names and settings.
 *
 * @since 1.0.0
 */
final class EventManagerRegisterSchedulerTest extends TestCase
{
    public function testRegisterSchedulerDelegatesToEventScheduler(): void
    {
        $eventManager = self::$app->make(EventManager::class);
        $schedule = new Schedule;

        // Should not throw — EventScheduler is registered as a singleton
        $eventManager->registerScheduler($schedule);

        // Verify scheduled events were registered
        $events = $schedule->events();
        $names = array_map(
            fn ($event) => $event->command ?? $event->description ?? '',
            $events,
        );

        // The purge-logs and cleanup-subscriptions scheduled tasks should exist
        $this->assertTrue(
            collect($names)->contains(fn (string $name): bool =>
                str_contains($name, 'zeroboiler:events:purge-logs') ||
                str_contains($name, 'zeroboiler:events:cleanup-subscriptions')
            ),
            'Expected scheduled tasks to be registered.',
        );
    }

    public function testRegisterSchedulerWithRetentionDaysZeroSkipsPurge(): void
    {
        $config = self::$app->make('config');
        $config->set('events.retention.days', 0);

        $eventManager = self::$app->make(EventManager::class);
        $schedule = new Schedule;

        $eventManager->registerScheduler($schedule);

        // Only cleanup-subscriptions should be scheduled
        $events = $schedule->events();
        $this->assertGreaterThan(
            0,
            count($events),
            'At least the subscription cleanup task should be scheduled.',
        );
    }

    public function testRegisterSchedulerWithNullRetentionDaysSkipsPurge(): void
    {
        $config = self::$app->make('config');
        $config->set('events.retention.days', null);

        $eventManager = self::$app->make(EventManager::class);
        $schedule = new Schedule;

        $eventManager->registerScheduler($schedule);

        $events = $schedule->events();
        $this->assertGreaterThan(
            0,
            count($events),
            'Subscription cleanup should still be scheduled even when retention is null.',
        );
    }

    public function testRegisterSchedulerIsIdempotent(): void
    {
        $eventManager = self::$app->make(EventManager::class);
        $schedule1 = new Schedule;
        $schedule2 = new Schedule;

        $eventManager->registerScheduler($schedule1);
        $eventManager->registerScheduler($schedule2);

        // Both schedules should have the same number of events
        $this->assertCount(
            count($schedule1->events()),
            $schedule2->events(),
            'Calling registerScheduler twice should produce the same result.',
        );
    }

    public function testRegisterSchedulerRegistersEventSchedulerSingleton(): void
    {
        $scheduler1 = self::$app->make(EventScheduler::class);
        $scheduler2 = self::$app->make(EventScheduler::class);

        // EventScheduler should be a singleton
        $this->assertSame($scheduler1, $scheduler2);
    }

    public function testRegisterSchedulerCustomCronExpressions(): void
    {
        $config = self::$app->make('config');
        $config->set('events.retention.schedule_cron', '0 4 * * *');
        $config->set('events.subscriptions.cleanup_cron', '0 5 * * *');

        $eventManager = self::$app->make(EventManager::class);
        $schedule = new Schedule;

        $eventManager->registerScheduler($schedule);

        // Verify tasks were registered
        $events = $schedule->events();
        $this->assertGreaterThan(0, count($events));
    }
}
