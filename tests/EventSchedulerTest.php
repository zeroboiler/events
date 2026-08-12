<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Console\Scheduling\Schedule;
use ZeroBoiler\Events\EventScheduler;

test('register adds purge logs scheduled task when retention days is configured', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', 30);
    app('config')->set('events.retention.include_pending', false);
    app('config')->set('events.retention.schedule_cron', '0 2 * * *');

    $scheduler = new EventScheduler;
    $scheduler->register($schedule);

    $events = $schedule->events();

    expect($events)->toHaveCount(2);

    $purgeEvent = $events[0];
    expect($purgeEvent->command)
        ->toBeNull(); // Closure-based
    expect($purgeEvent->expression)
        ->toBe('0 2 * * *');
});

test('register skips purge when retention days is null', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', null);

    $scheduler = new EventScheduler;
    $scheduler->register($schedule);

    $events = $schedule->events();

    // Only the subscription cleanup should be registered
    expect($events)->toHaveCount(1);
});

test('register skips purge when retention days is zero', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', 0);

    $scheduler = new EventScheduler;
    $scheduler->register($schedule);

    $events = $schedule->events();

    expect($events)->toHaveCount(1);
});

test('register uses custom cron expression for log purge', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', 14);
    app('config')->set('events.retention.schedule_cron', '0 */6 * * *');

    $scheduler = new EventScheduler;
    $scheduler->register($schedule);

    $events = $schedule->events();

    $purgeEvent = $events[0];
    expect($purgeEvent->expression)
        ->toBe('0 */6 * * *');
});

test('register adds subscription cleanup scheduled task', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.subscriptions.cleanup_cron', '0 3 * * *');

    $scheduler = new EventScheduler;
    $scheduler->register($schedule);

    $events = $schedule->events();

    // At least one event should be the subscription cleanup
    $cleanupEvent = $events[count($events) - 1];
    expect($cleanupEvent->command)
        ->toBeNull();
    expect($cleanupEvent->expression)
        ->toBe('0 3 * * *');
});

test('register uses custom cron for subscription cleanup', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.subscriptions.cleanup_cron', '30 1 * * 0');

    $scheduler = new EventScheduler;
    $scheduler->register($schedule);

    $events = $schedule->events();

    $cleanupEvent = $events[count($events) - 1];
    expect($cleanupEvent->expression)
        ->toBe('30 1 * * 0');
});

test('EventScheduler is a final class', function (): void {
    $reflection = new \ReflectionClass(EventScheduler::class);

    expect($reflection->isFinal())->toBeTrue();
});
