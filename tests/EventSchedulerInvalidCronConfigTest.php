<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Console\Scheduling\Schedule;
use ZeroBoiler\Events\EventScheduler;

/**
 * Tests for EventScheduler with invalid or edge-case cron configurations.
 *
 * Verifies that the scheduler handles empty, non-string, and
 * null cron values gracefully by falling back to defaults.
 *
 * @see \ZeroBoiler\Events\EventScheduler
 *
 * @since 1.0.0
 */
final class EventSchedulerInvalidCronConfigTest extends TestCase
{
    public function test_empty_retention_cron_falls_back_to_default(): void
    {
        // Override the retention schedule_cron to empty string
        $config = self::$app->make('config');
        $config->set('events.retention.days', 7);
        $config->set('events.retention.schedule_cron', '');

        $scheduler = self::$app->make(EventScheduler::class);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        // Should still register the purge-logs event with default cron
        $purgeEvent = null;
        foreach ($events as $event) {
            if ($event->description === 'zeroboiler:events:purge-logs') {
                $purgeEvent = $event;
                break;
            }
        }

        expect($purgeEvent)->not->toBeNull();
    }

    public function test_empty_cleanup_cron_falls_back_to_default(): void
    {
        $config = self::$app->make('config');
        $config->set('events.subscriptions.cleanup_cron', '');

        $scheduler = self::$app->make(EventScheduler::class);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        $cleanupEvent = null;
        foreach ($events as $event) {
            if ($event->description === 'zeroboiler:events:cleanup-subscriptions') {
                $cleanupEvent = $event;
                break;
            }
        }

        expect($cleanupEvent)->not->toBeNull();
    }

    public function test_null_retention_days_skips_purge_registration(): void
    {
        $config = self::$app->make('config');
        $config->set('events.retention.days', null);

        $scheduler = self::$app->make(EventScheduler::class);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        $purgeEvent = null;
        foreach ($events as $event) {
            if ($event->description === 'zeroboiler:events:purge-logs') {
                $purgeEvent = $event;
                break;
            }
        }

        expect($purgeEvent)->toBeNull();
    }

    public function test_zero_retention_days_skips_purge_registration(): void
    {
        $config = self::$app->make('config');
        $config->set('events.retention.days', 0);

        $scheduler = self::$app->make(EventScheduler::class);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        $purgeEvent = null;
        foreach ($events as $event) {
            if ($event->description === 'zeroboiler:events:purge-logs') {
                $purgeEvent = $event;
                break;
            }
        }

        expect($purgeEvent)->toBeNull();
    }

    public function test_negative_retention_days_skips_purge_registration(): void
    {
        $config = self::$app->make('config');
        $config->set('events.retention.days', -5);

        $scheduler = self::$app->make(EventScheduler::class);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        $purgeEvent = null;
        foreach ($events as $event) {
            if ($event->description === 'zeroboiler:events:purge-logs') {
                $purgeEvent = $event;
                break;
            }
        }

        expect($purgeEvent)->toBeNull();
    }

    public function test_string_retention_days_parsed_correctly(): void
    {
        $config = self::$app->make('config');
        $config->set('events.retention.days', '45');

        $scheduler = self::$app->make(EventScheduler::class);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $events = $schedule->events();

        $purgeEvent = null;
        foreach ($events as $event) {
            if ($event->description === 'zeroboiler:events:purge-logs') {
                $purgeEvent = $event;
                break;
            }
        }

        expect($purgeEvent)->not->toBeNull();
    }

    public function test_both_scheduled_tasks_registered_with_defaults(): void
    {
        $scheduler = self::$app->make(EventScheduler::class);
        $schedule = new Schedule;

        $scheduler->register($schedule);

        $descriptions = array_map(
            fn ($event) => $event->description,
            $schedule->events(),
        );

        expect($descriptions)->toContain('zeroboiler:events:purge-logs');
        expect($descriptions)->toContain('zeroboiler:events:cleanup-subscriptions');
    }
}
