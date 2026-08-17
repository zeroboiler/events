<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Tests\TestCase;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

uses(TestCase::class);

test('DispatchTriggerJob serializes and unserializes correctly', function (): void {
    $payload = [
        'url' => 'https://example.com/webhook',
        'subscription_id' => 'sub-uuid-123',
        'order_id' => 456,
        'total' => 99.99,
    ];

    $job = new DispatchTriggerJob(
        triggerId: 'trigger-uuid-abc',
        event: 'order.placed',
        payload: $payload,
    );

    // Verify properties are set
    expect($job->triggerId)->toBe('trigger-uuid-abc');
    expect($job->event)->toBe('order.placed');
    expect($job->payload)->toBe($payload);

    // Verify config-driven defaults are applied
    expect($job->tries)->toBeInt()->toBeGreaterThan(0);
    expect($job->queue)->toBeString()->toBe('default');
    expect($job->backoff)->toBeArray();
    expect($job->connection)->toBeNull();
});

test('DispatchTriggerJob reads config values at construction time', function (): void {
    Config::set('events.retry.tries', 5);
    Config::set('events.retry.backoff', '30,60,120');
    Config::set('events.queue.queue', 'events-high');
    Config::set('events.queue.connection', 'redis');

    $job = new DispatchTriggerJob('t-1', 'test.event', ['key' => 'value']);

    expect($job->tries)->toBe(5);
    expect($job->backoff)->toBe([30, 60, 120]);
    expect($job->queue)->toBe('events-high');
    expect($job->connection)->toBe('redis');
});

test('DispatchTriggerJob handles array backoff config', function (): void {
    Config::set('events.retry.backoff', [10, 20, 30, 60]);

    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    expect($job->backoff)->toBe([10, 20, 30, 60]);
});

test('DispatchTriggerJob handles invalid tries config gracefully', function (): void {
    Config::set('events.retry.tries', -1);

    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    // Falls back to default of 3
    expect($job->tries)->toBe(3);
});

test('DispatchTriggerJob handles zero tries config gracefully', function (): void {
    Config::set('events.retry.tries', 0);

    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    // Falls back to default of 3
    expect($job->tries)->toBe(3);
});

test('DispatchTriggerJob handles empty string queue name gracefully', function (): void {
    Config::set('events.queue.queue', '');

    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    // Falls back to 'default'
    expect($job->queue)->toBe('default');
});

test('DispatchTriggerJob handles empty string connection gracefully', function (): void {
    Config::set('events.queue.connection', '');

    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    // Empty string is skipped — connection remains null
    expect($job->connection)->toBeNull();
});

test('DispatchTriggerJob handles backoff with extra whitespace', function (): void {
    Config::set('events.retry.backoff', '60 , 300 , 900 ');

    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    expect($job->backoff)->toBe([60, 300, 900]);
});

test('DispatchTriggerJob payload preserves nested structures', function (): void {
    $payload = [
        'user' => [
            'name' => 'John',
            'address' => [
                'city' => 'Istanbul',
                'country' => 'Turkey',
            ],
        ],
        'items' => [
            ['sku' => 'A1', 'qty' => 2],
            ['sku' => 'B2', 'qty' => 1],
        ],
    ];

    $job = new DispatchTriggerJob('t-1', 'order.created', $payload);

    expect($job->payload)->toBe($payload);
    expect($job->payload['user']['address']['city'])->toBe('Istanbul');
    expect($job->payload['items'][0]['sku'])->toBe('A1');
});

test('DispatchTriggerJob handles non-integer tries config', function (): void {
    Config::set('events.retry.tries', 'five');

    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    // Falls back to default of 3
    expect($job->tries)->toBe(3);
});

test('DispatchTriggerJob handles single-value backoff', function (): void {
    Config::set('events.retry.backoff', '120');

    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    expect($job->backoff)->toBe([120]);
});

test('DispatchTriggerJob handles empty payload', function (): void {
    $job = new DispatchTriggerJob('t-1', 'test.event', []);

    expect($job->payload)->toBe([]);
    expect($job->triggerId)->toBe('t-1');
    expect($job->event)->toBe('test.event');
});

test('DispatchTriggerJob properties are readonly', function (): void {
    $job = new DispatchTriggerJob('t-1', 'test.event', ['key' => 'value']);

    // Verify the properties exist and are accessible
    expect($job->triggerId)->toBeString();
    expect($job->event)->toBeString();
    expect($job->payload)->toBeArray();
    expect($job->tries)->toBeInt();
    expect($job->backoff)->toBeArray();
    expect($job->queue)->toBeString();
    expect($job->connection)->toBeNull();
});
