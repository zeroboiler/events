<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('EventManager direct EscapesWildcardLike trait usage', function (): void {
    test('listTriggers with wildcard pattern uses wildcardToLike directly on EventManager', function (): void {
        // Create triggers with different event patterns
        Trigger::factory()->create(['event' => 'order.placed', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'order.shipped', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'payment.received', 'enabled' => true]);

        // listTriggers uses wildcardToLike which is now directly on EventManager
        $results = app(EventManager::class)->listTriggers('order.*');

        expect($results)->toHaveCount(2);
        expect($results->pluck('event')->toArray())
            ->toContain('order.placed')
            ->toContain('order.shipped')
            ->not->toContain('payment.received');
    });

    test('listTriggers with exact event pattern bypasses wildcardToLike', function (): void {
        Trigger::factory()->create(['event' => 'order.placed', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'order.shipped', 'enabled' => true]);

        $results = app(EventManager::class)->listTriggers('order.placed');

        expect($results)->toHaveCount(1);
        expect($results->first()->event)->toBe('order.placed');
    });

    test('listTriggers with cross-segment wildcard', function (): void {
        Trigger::factory()->create(['event' => 'order.placed', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'order.placed.extra', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'payment.done', 'enabled' => true]);

        $results = app(EventManager::class)->listTriggers('order.**');

        expect($results)->toHaveCount(2);
        expect($results->pluck('event')->toArray())
            ->toContain('order.placed')
            ->toContain('order.placed.extra')
            ->not->toContain('payment.done');
    });

    test('EventManager has EscapesWildcardLike in direct class_uses', function (): void {
        $directUses = class_uses(EventManager::class);

        expect($directUses)->toBeArray()
            ->toHaveKey(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
    });

    test('wildcardToLike returns null for non-wildcard patterns', function (): void {
        $manager = app(EventManager::class);

        // Use reflection to access the protected method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('wildcardToLike');

        expect($method->invoke($manager, 'order.placed'))->toBeNull();
        expect($method->invoke($manager, 'payment.received'))->toBeNull();
    });

    test('wildcardToLike converts asterisks to percent signs', function (): void {
        $manager = app(EventManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('wildcardToLike');

        expect($method->invoke($manager, 'order.*'))->toBe('order.%');
        expect($method->invoke($manager, '*.order.*'))->toBe('%.order.%');
    });

    test('wildcardToLike escapes special SQL characters', function (): void {
        $manager = app(EventManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('wildcardToLike');

        // Percent signs and underscores should be escaped
        expect($method->invoke($manager, 'user_%'))
            ->toBe('user\\%');
    });
});
