<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

describe('Events config completeness', function (): void {
    test('config has all required top-level keys', function (): void {
        $config = config('events');

        expect($config)->toBeArray()
            ->and($config)->toHaveKeys([
                'table_names',
                'queue',
                'retry',
                'retention',
                'subscriptions',
                'disabled',
                'payload_max_bytes',
                'wildcard_cache_ttl',
            ]);
    });

    test('config table_names has all required table keys', function (): void {
        $tables = config('events.table_names');

        expect($tables)->toBeArray()
            ->and($tables)->toHaveKeys([
                'triggers',
                'event_logs',
                'subscriptions',
            ])
            ->and($tables['triggers'])->toBeString()
            ->and($tables['event_logs'])->toBeString()
            ->and($tables['subscriptions'])->toBeString();
    });

    test('config queue has connection and queue keys', function (): void {
        $queue = config('events.queue');

        expect($queue)->toBeArray()
            ->and($queue)->toHaveKeys(['connection', 'queue']);
    });

    test('config retry has tries and backoff keys', function (): void {
        $retry = config('events.retry');

        expect($retry)->toBeArray()
            ->and($retry)->toHaveKeys(['tries', 'backoff'])
            ->and($retry['tries'])->toBeGreaterThanOrEqual(1);
    });

    test('config retention has days and include_pending keys', function (): void {
        $retention = config('events.retention');

        expect($retention)->toBeArray()
            ->and($retention)->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
    });

    test('config subscriptions has all required keys', function (): void {
        $subs = config('events.subscriptions');

        expect($subs)->toBeArray()
            ->and($subs)->toHaveKeys([
                'auto_generate_secret',
                'secret_length',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ])
            ->and($subs['max_failures'])->toBeGreaterThanOrEqual(1)
            ->and($subs['timeout'])->toBeGreaterThanOrEqual(1)
            ->and($subs['signature_algorithm'])->toBeString();
    });

    test('config wildcard_cache_ttl is a non-negative integer', function (): void {
        $ttl = config('events.wildcard_cache_ttl');

        expect($ttl)->toBeInt()
            ->and($ttl)->toBeGreaterThanOrEqual(0);
    });

    test('config payload_max_bytes is a non-negative integer', function (): void {
        $max = config('events.payload_max_bytes');

        expect($max)->toBeInt()
            ->and($max)->toBeGreaterThanOrEqual(0);
    });

    test('config disabled is a boolean', function (): void {
        $disabled = config('events.disabled');

        expect($disabled)->toBeBool();
    });
});
