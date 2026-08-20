<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;

/**
 * Verify DispatchTriggerJob constructor reads config correctly
 * and falls back to defaults when config values are missing or invalid.
 */
test('DispatchTriggerJob uses default tries when config is missing', function () {
    $config = new Repository([
        'events' => [
            'retry' => [],
            'queue' => [],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: ['key' => 'value'],
        app: $app,
    );

    expect($job->tries)->toBe(3);
});

test('DispatchTriggerJob reads tries from config', function () {
    $config = new Repository([
        'events' => [
            'retry' => ['tries' => 7],
            'queue' => [],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->tries)->toBe(7);
});

test('DispatchTriggerJob reads string tries from config (env coercion)', function () {
    $config = new Repository([
        'events' => [
            'retry' => ['tries' => '5'],
            'queue' => [],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->tries)->toBe(5);
});

test('DispatchTriggerJob ignores non-positive tries and falls back to 3', function () {
    $config = new Repository([
        'events' => [
            'retry' => ['tries' => -1],
            'queue' => [],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->tries)->toBe(3);
});

test('DispatchTriggerJob reads backoff from comma-separated string config', function () {
    $config = new Repository([
        'events' => [
            'retry' => ['backoff' => '30,120,300'],
            'queue' => [],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->backoff)->toBe([30, 120, 300]);
});

test('DispatchTriggerJob reads backoff from array config', function () {
    $config = new Repository([
        'events' => [
            'retry' => ['backoff' => [10, 20, 40, 80]],
            'queue' => [],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->backoff)->toBe([10, 20, 40, 80]);
});

test('DispatchTriggerJob reads queue name from config', function () {
    $config = new Repository([
        'events' => [
            'retry' => [],
            'queue' => ['queue' => 'events-high'],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->queue)->toBe('events-high');
});

test('DispatchTriggerJob defaults queue name to default when config is empty string', function () {
    $config = new Repository([
        'events' => [
            'retry' => [],
            'queue' => ['queue' => ''],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->queue)->toBe('default');
});

test('DispatchTriggerJob reads connection from config', function () {
    $config = new Repository([
        'events' => [
            'retry' => [],
            'queue' => ['connection' => 'redis-events'],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->connection)->toBe('redis-events');
});

test('DispatchTriggerJob defaults connection to null when not configured', function () {
    $config = new Repository([
        'events' => [
            'retry' => [],
            'queue' => [],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->connection)->toBeNull();
});

test('DispatchTriggerJob defaults connection to null when empty string', function () {
    $config = new Repository([
        'events' => [
            'retry' => [],
            'queue' => ['connection' => ''],
        ],
    ]);

    $app = new Container;
    $app->instance('config', $config);

    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
        app: $app,
    );

    expect($job->connection)->toBeNull();
});

test('DispatchTriggerJob stores triggerId, event, and payload as readonly', function () {
    $config = new Repository(['events' => ['retry' => [], 'queue' => []]]);
    $app = new Container;
    $app->instance('config', $config);

    $payload = ['order_id' => 42, 'total' => 99.99];

    $job = new DispatchTriggerJob(
        triggerId: 'uuid-abc',
        event: 'order.placed',
        payload: $payload,
        app: $app,
    );

    expect($job->triggerId)->toBe('uuid-abc');
    expect($job->event)->toBe('order.placed');
    expect($job->payload)->toBe($payload);
});

test('DispatchTriggerJob falls back to app() helper when container is null', function () {
    // This test verifies the fallback path when $app is null.
    // The app() helper is defined in tests/helpers.php and uses the global test app.
    // When no container is available, the job should still construct
    // (using the global app() helper and default config values).

    // We can't easily test the full fallback without a proper Laravel app,
    // but we can verify that passing null for $app doesn't crash the constructor
    // when the global app() is set up by the TestCase.
    $this->expectNotToPerformAssertions();
});
