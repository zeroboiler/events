<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

test('config has all 8 top-level keys with defaults', function (): void {
    $config = $this->app->make('config');

    expect($config->get('events'))->toBeArray();

    // All 8 top-level keys must exist
    $keys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];

    foreach ($keys as $key) {
        expect($config->get("events.{$key}"))->not()->toBeNull(
            "Config key events.{$key} must exist"
        );
    }
});

test('table_names config has all 3 entries', function (): void {
    $config = $this->app->make('config');
    $tableNames = $config->get('events.table_names');

    expect($tableNames)->toBeArray();
    expect($tableNames)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    expect($tableNames['triggers'])->toBe('triggers');
    expect($tableNames['event_logs'])->toBe('event_logs');
    expect($tableNames['subscriptions'])->toBe('event_subscriptions');
});

test('queue config has connection and queue keys', function (): void {
    $config = $this->app->make('config');
    $queue = $config->get('events.queue');

    expect($queue)->toBeArray();
    expect($queue)->toHaveKeys(['connection', 'queue']);
    expect($queue['queue'])->toBe('default');
});

test('retry config has tries and backoff keys', function (): void {
    $config = $this->app->make('config');
    $retry = $config->get('events.retry');

    expect($retry)->toBeArray();
    expect($retry)->toHaveKeys(['tries', 'backoff']);
    expect($retry['tries'])->toBe(3);
    expect($retry['backoff'])->toBe('60,300,900');
});
