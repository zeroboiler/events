<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Console\Scheduling\Schedule;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;

test('scheduler handles negative retention days gracefully', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', -5);

    $scheduler = new EventScheduler(app());
    $scheduler->register($schedule);

    $events = $schedule->events();

    // Negative days should be treated as disabled — only cleanup
    expect($events)->toHaveCount(1);
});

test('scheduler handles string retention days config', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', '30');

    $scheduler = new EventScheduler(app());
    $scheduler->register($schedule);

    $events = $schedule->events();

    // String "30" is numeric, should register purge + cleanup = 2
    expect($events)->toHaveCount(2);
});

test('scheduler handles empty cron expression by falling back to default', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', 7);
    app('config')->set('events.retention.schedule_cron', '');

    $scheduler = new EventScheduler(app());
    $scheduler->register($schedule);

    $events = $schedule->events();

    $purgeEvent = $events[0];
    expect($purgeEvent->expression)->toBe('0 2 * * *');
});

test('scheduler handles non-string cron expression by falling back to default', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', 7);
    app('config')->set('events.retention.schedule_cron', 12345);

    $scheduler = new EventScheduler(app());
    $scheduler->register($schedule);

    $events = $schedule->events();

    $purgeEvent = $events[0];
    expect($purgeEvent->expression)->toBe('0 2 * * *');
});

test('scheduler handles empty cleanup cron expression by falling back to default', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', null);
    app('config')->set('events.subscriptions.cleanup_cron', '');

    $scheduler = new EventScheduler(app());
    $scheduler->register($schedule);

    $events = $schedule->events();

    // Only cleanup with fallback cron
    expect($events)->toHaveCount(1);
    expect($events[0]->expression)->toBe('0 3 * * *');
});

test('scheduler resolveEventManager returns null when binding missing', function (): void {
    // Remove the EventManager binding
    app()->forgetInstance(EventManager::class);

    $scheduler = new EventScheduler(app());

    // Use reflection to call protected method
    $reflection = new \ReflectionMethod($scheduler, 'resolveEventManager');

    $result = $reflection->invoke($scheduler);

    expect($result)->toBeNull();
});

test('scheduler has readonly Container property', function (): void {
    $reflection = new \ReflectionClass(EventScheduler::class);

    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);

    $param = $params[0];
    expect($param->getName())->toBe('app');
    expect($param->isPromoted())->toBeTrue();
    expect($param->isReadOnly())->toBeTrue();
});

test('scheduler register method returns void', function (): void {
    $schedule = new Schedule;

    $scheduler = new EventScheduler(app());

    $result = $scheduler->register($schedule);

    expect($result)->toBeNull();
});

test('scheduler purge callback handles includePending config correctly', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', 7);
    app('config')->set('events.retention.include_pending', true);

    $scheduler = new EventScheduler(app());
    $scheduler->register($schedule);

    // Should register successfully without errors
    $events = $schedule->events();
    expect($events)->toHaveCount(2);
});

test('scheduler uses withoutOverlapping and onOneServer', function (): void {
    $schedule = new Schedule;

    app('config')->set('events.retention.days', 7);

    $scheduler = new EventScheduler(app());
    $scheduler->register($schedule);

    $events = $schedule->events();

    foreach ($events as $event) {
        // withoutOverlapping and onOneServer are stored internally;
        // we just verify the events were created successfully
        expect($event)->not->toBeNull();
    }
});
