<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

describe('DispatchTriggerJob config edge cases', function (): void {
    it('handles zero tries config gracefully', function (): void {
        config(['events.retry.tries' => 0]);

        $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
            'trigger-uuid',
            'test.event',
            ['key' => 'value'],
        );

        // When tries is 0, it falls back to default 3
        expect($job->tries)->toBe(3);
    });

    it('handles negative tries config gracefully', function (): void {
        config(['events.retry.tries' => -5]);

        $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
            'trigger-uuid',
            'test.event',
            ['key' => 'value'],
        );

        // Negative tries should fall back to default 3
        expect($job->tries)->toBe(3);
    });

    it('handles empty backoff string config', function (): void {
        config(['events.retry.backoff' => '']);

        $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
            'trigger-uuid',
            'test.event',
            ['key' => 'value'],
        );

        // Empty string should produce empty array after trim, which is [] → fallback
        // Actually: explode(',', '') returns [''], then trim gives '', (int)'' = 0
        // So backoff would be [0], which is valid
        expect($job->backoff)->toBe([0]);
    });

    it('handles backoff with whitespace entries', function (): void {
        config(['events.retry.backoff' => ' 60 , 300 , 900 ']);

        $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
            'trigger-uuid',
            'test.event',
            ['key' => 'value'],
        );

        expect($job->backoff)->toBe([60, 300, 900]);
    });

    it('handles empty queue name config', function (): void {
        config(['events.queue.queue' => '']);

        $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
            'trigger-uuid',
            'test.event',
            ['key' => 'value'],
        );

        // Empty string queue name should fall back to 'default'
        expect($job->queue)->toBe('default');
    });

    it('preserves complex nested payload', function (): void {
        $payload = [
            'user' => [
                'id' => 123,
                'name' => 'Test User',
                'roles' => ['admin', 'editor'],
            ],
            'metadata' => [
                'nested' => [
                    'deep' => true,
                    'count' => 42,
                ],
            ],
        ];

        $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
            'trigger-uuid',
            'test.event',
            $payload,
        );

        expect($job->payload)->toBe($payload);
        expect($job->payload['user']['roles'])->toBe(['admin', 'editor']);
        expect($job->payload['metadata']['nested']['deep'])->toBeTrue();
    });
});
