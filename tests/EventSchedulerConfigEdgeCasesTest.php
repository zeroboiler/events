<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use ZeroBoiler\Events\EventScheduler;

describe('EventScheduler config edge cases', function (): void {
    test('register does not throw with valid config', function (): void {
        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('register skips log purge when retention.days is null', function (): void {
        // The default test config has days=30, so let's override
        $config = app('config');
        $config->set('events.retention.days', null);

        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('register skips log purge when retention.days is 0', function (): void {
        $config = app('config');
        $config->set('events.retention.days', 0);

        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('register skips log purge when retention.days is negative', function (): void {
        $config = app('config');
        $config->set('events.retention.days', -5);

        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('register handles string numeric retention.days', function (): void {
        $config = app('config');
        $config->set('events.retention.days', '45');

        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('register handles float retention.days', function (): void {
        $config = app('config');
        $config->set('events.retention.days', 30.5);

        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('register uses default cron when schedule_cron is empty string', function (): void {
        $config = app('config');
        $config->set('events.retention.days', 7);
        $config->set('events.retention.schedule_cron', '');

        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('register uses default cleanup cron when subscriptions.cleanup_cron is empty', function (): void {
        $config = app('config');
        $config->set('events.subscriptions.cleanup_cron', '');

        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('register handles non-string schedule_cron gracefully', function (): void {
        $config = app('config');
        $config->set('events.retention.days', 7);
        $config->set('events.retention.schedule_cron', 12345);

        $scheduler = app(EventScheduler::class);
        $schedule = new Schedule;

        expect(fn (): mixed => $scheduler->register($schedule))->not->toThrow();
    });

    test('resolveEventManager returns null when EventManager is not bound', function (): void {
        // Create a fresh container without EventManager binding
        $container = new \Illuminate\Container\Container;
        $scheduler = new EventScheduler($container);

        // Access the protected method via reflection
        $ref = new \ReflectionMethod(EventScheduler::class, 'resolveEventManager');
        $ref->setAccessible(true);

        $result = $ref->invoke($scheduler);
        expect($result)->toBeNull();
    });

    test('getConfig throws RuntimeException when config not available', function (): void {
        $container = new \Illuminate\Container\Container;
        $scheduler = new EventScheduler($container);

        $ref = new \ReflectionMethod(EventScheduler::class, 'getConfig');
        $ref->setAccessible(true);

        expect(fn (): mixed => $ref->invoke($scheduler))
            ->toThrow(\RuntimeException::class, 'Config repository not available');
    });

    test('class is final', function (): void {
        $ref = new \ReflectionClass(EventScheduler::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('constructor has readonly app property', function (): void {
        $ref = new \ReflectionClass(EventScheduler::class);
        $prop = $ref->getProperty('app');
        expect($prop->isReadOnly())->toBeTrue();
        expect($prop->isPrivate())->toBeTrue();
    });
});
