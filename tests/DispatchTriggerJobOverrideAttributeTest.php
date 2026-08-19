<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Jobs\DispatchTriggerJob;

/**
 * Verify that DispatchTriggerJob::failed() has the #[\Override] attribute.
 *
 * This ensures PHPStan 9 can validate that the method signature
n * matches the parent (InteractsWithQueue::failed()).
 *
 * @since 5.97.0
 */
test('DispatchTriggerJob::failed() has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(DispatchTriggerJob::class, 'failed');
    $attributes = $method->getAttributes(\Override::class);

    expect($attributes)->toHaveCount(1);
});

test('DispatchTriggerJob::handle() has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(DispatchTriggerJob::class, 'handle');
    $attributes = $method->getAttributes(\Override::class);

    expect($attributes)->toHaveCount(1);
});

test('DispatchTriggerJob readonly properties have correct types', function (): void {
    $job = new DispatchTriggerJob('trigger-id', 'test.event', ['key' => 'val']);

    expect($job->triggerId)->toBe('trigger-id')
        ->and($job->event)->toBe('test.event')
        ->and($job->payload)->toBe(['key' => 'val'])
        ->and($job->tries)->toBeInt()
        ->and($job->backoff)->toBeArray()
        ->and($job->queue)->toBeString()
        ->and($job->connection)->toBeNull();
});
