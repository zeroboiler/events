<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Jobs\DispatchTriggerJob;

// Note: Container is not stored on the job, only used to read config values
// during construction. This test verifies the config reading is null-safe.

describe('DispatchTriggerJob constructor config null-safety', function (): void {
    test('constructor reads tries from config with integer default', function (): void {
        config(['events.retry.tries' => 5]);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            ['key' => 'value'],
        );

        expect($job->tries)->toBe(5);
    });

    test('constructor reads tries from config with numeric string', function (): void {
        config(['events.retry.tries' => '7']);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->tries)->toBe(7);
    });

    test('constructor defaults tries to 3 when config is null', function (): void {
        config(['events.retry.tries' => null]);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->tries)->toBe(3);
    });

    test('constructor reads backoff from config as comma-separated string', function (): void {
        config(['events.retry.backoff' => '30,60,120']);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->backoff)->toBe([30, 60, 120]);
    });

    test('constructor reads backoff from config as array', function (): void {
        config(['events.retry.backoff' => [10, 20, 30, 40]]);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->backoff)->toBe([10, 20, 30, 40]);
    });

    test('constructor defaults backoff to 60,300,900 when config is null', function (): void {
        config(['events.retry.backoff' => null]);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->backoff)->toBe([60, 300, 900]);
    });

    test('constructor reads queue name from config', function (): void {
        config(['events.queue.queue' => 'events-high']);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->queue)->toBe('events-high');
    });

    test('constructor defaults queue name to default when config is empty string', function (): void {
        config(['events.queue.queue' => '']);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->queue)->toBe('default');
    });

    test('constructor reads connection from config', function (): void {
        config(['events.queue.connection' => 'redis-events']);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->connection)->toBe('redis-events');
    });

    test('constructor defaults connection to null when not configured', function (): void {
        config(['events.queue.connection' => null]);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->connection)->toBeNull();
    });

    test('constructor defaults connection to null when config is empty string', function (): void {
        config(['events.queue.connection' => '']);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->connection)->toBeNull();
    });

    test('constructor defaults tries to 3 when config is non-numeric string', function (): void {
        config(['events.retry.tries' => 'not-a-number']);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->tries)->toBe(3);
    });

    test('constructor defaults tries to 3 when config is zero', function (): void {
        config(['events.retry.tries' => 0]);

        $job = new DispatchTriggerJob(
            (string) \Illuminate\Support\Str::uuid(),
            'test.event',
            [],
        );

        expect($job->tries)->toBe(3);
    });
});
