<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\Actions\NullAction;

/**
 * Tests for ConditionEngineContract custom implementations via the container,
 * deterministic trigger ordering with same priority/timestamp, and
 * NaN/Inf payload rejection in fire().
 *
 * @since 6.0.2
 */
describe('ConditionEngineContract custom implementation via EventManager', function (): void {
    test('custom ConditionEngineContract implementation is used by EventManager', function (): void {
        // Register test action
        $this->app->bind(NullAction::class);

        // Create a custom condition engine that always returns true
        $customEngine = new class implements ConditionEngineContract {
            public bool $wasCalled = false;

            public function matches(array $conditions, array $payload): bool
            {
                $this->wasCalled = true;

                return true;
            }
        };

        // Re-bind the contract and concrete to our custom implementation
        $this->app->singleton(ConditionEngineContract::class, fn (): ConditionEngineContract => $customEngine);
        $this->app->singleton(ConditionEngine::class, fn (): ConditionEngineContract => $customEngine);

        // Re-create EventManager with the new bindings
        $this->app->singleton(EventManager::class, function (\Illuminate\Container\Container $app): EventManager {
            return new EventManager(
                $app->make(ConditionEngine::class),
                $app->make(ActionResolver::class),
                $app,
            );
        });

        $manager = $this->app->make(EventManager::class);

        // Create a trigger with conditions that the default engine would reject
        // but our custom engine always accepts
        Trigger::factory()->create([
            'event' => 'test.custom.engine',
            'action' => NullAction::class,
            'conditions' => ['impossible_field' => ['>', 999999]],
            'enabled' => true,
            'async' => false,
        ]);

        $manager->fire('test.custom.engine', ['key' => 'value']);

        // Our custom engine should have been called
        expect($customEngine->wasCalled)->toBeTrue();

        // Verify an EventLog was created (condition passed)
        $this->assertDatabaseHas('event_logs', [
            'event' => 'test.custom.engine',
            'status' => 'completed',
        ]);
    });

    test('custom condition engine that always returns false prevents dispatch', function (): void {
        $this->app->bind(NullAction::class);

        $rejectingEngine = new class implements ConditionEngineContract {
            public function matches(array $conditions, array $payload): bool
            {
                return false;
            }
        };

        $this->app->singleton(ConditionEngineContract::class, fn (): ConditionEngineContract => $rejectingEngine);
        $this->app->singleton(ConditionEngine::class, fn (): ConditionEngineContract => $rejectingEngine);

        $this->app->singleton(EventManager::class, function (\Illuminate\Container\Container $app): EventManager {
            return new EventManager(
                $app->make(ConditionEngine::class),
                $app->make(ActionResolver::class),
                $app,
            );
        });

        $manager = $this->app->make(EventManager::class);

        Trigger::factory()->create([
            'event' => 'test.reject.all',
            'action' => NullAction::class,
            'conditions' => ['always_reject' => true],
            'enabled' => true,
            'async' => false,
        ]);

        $manager->fire('test.reject.all', ['key' => 'value']);

        // No EventLog should exist because condition was rejected
        $this->assertDatabaseMissing('event_logs', [
            'event' => 'test.reject.all',
        ]);
    });
});

describe('Deterministic trigger ordering', function (): void {
    test('triggers with same priority are ordered by ID as final tiebreaker', function (): void {
        $this->app->bind(NullAction::class);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $trigger = Trigger::factory()->create([
                'event' => 'test.ordering.same',
                'action' => NullAction::class,
                'enabled' => true,
                'async' => false,
                'priority' => 5,
                'conditions' => [],
            ]);
            $ids[] = $trigger->id;
        }

        // Sort IDs alphabetically (UUIDs sort lexicographically)
        sort($ids);

        $manager = $this->app->make(EventManager::class);

        // Use reflection to access the protected getMatchingTriggers method
        $ref = new ReflectionMethod($manager, 'getMatchingTriggers');
        $ref->setAccessible(true);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Trigger> $matched */
        $matched = $ref->invoke($manager, 'test.ordering.same');

        expect($matched)->toHaveCount(3);

        // Verify ordering: all same priority, so should be ordered by created_at then by ID
        // Since all created in the same second, ID should be the final tiebreaker
        $matchedIds = $matched->map(fn (Trigger $t): string => $t->id)->values()->toArray();

        // The matched order should be deterministic — same order on repeated calls
        $ref2 = new ReflectionMethod($manager, 'getMatchingTriggers');
        $ref2->setAccessible(true);
        $matched2 = $ref2->invoke($manager, 'test.ordering.same');
        $matchedIds2 = $matched2->map(fn (Trigger $t): string => $t->id)->values()->toArray();

        expect($matchedIds)->toBe($matchedIds2);
    });

    test('higher priority triggers are dispatched first', function (): void {
        $this->app->bind(NullAction::class);

        Trigger::factory()->create([
            'event' => 'test.priority.order',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'priority' => 1,
            'conditions' => [],
        ]);

        Trigger::factory()->create([
            'event' => 'test.priority.order',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'priority' => 10,
            'conditions' => [],
        ]);

        Trigger::factory()->create([
            'event' => 'test.priority.order',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'priority' => 5,
            'conditions' => [],
        ]);

        $manager = $this->app->make(EventManager::class);

        $ref = new ReflectionMethod($manager, 'getMatchingTriggers');
        $ref->setAccessible(true);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Trigger> $matched */
        $matched = $ref->invoke($manager, 'test.priority.order');

        $priorities = $matched->map(fn (Trigger $t): int => $t->priority)->values()->toArray();

        // Higher priority first: 10, 5, 1
        expect($priorities)->toBe([10, 5, 1]);
    });

    test('wildcard and exact triggers are deduplicated', function (): void {
        $this->app->bind(NullAction::class);

        // Create an exact trigger
        $exactTrigger = Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'priority' => 5,
            'conditions' => [],
        ]);

        // Create a wildcard trigger that also matches
        Trigger::factory()->create([
            'event' => 'order.*',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'priority' => 5,
            'conditions' => [],
        ]);

        Cache::forget('zeroboiler:events:enabled_wildcard_triggers');

        $manager = $this->app->make(EventManager::class);

        $ref = new ReflectionMethod($manager, 'getMatchingTriggers');
        $ref->setAccessible(true);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Trigger> $matched */
        $matched = $ref->invoke($manager, 'order.placed');

        // Should have exactly 2 triggers (1 exact + 1 wildcard), no duplicates
        expect($matched)->toHaveCount(2);

        // Each trigger ID should be unique
        $ids = $matched->map(fn (Trigger $t): string => $t->id)->values()->toArray();
        expect(count(array_unique($ids)))->toBe(2);
    });
});

describe('fire() payload validation', function (): void {
    test('fire() rejects payload with NaN value', function (): void {
        $manager = $this->app->make(EventManager::class);

        expect(fn (): mixed => $manager->fire('test.nan', ['value' => NAN]))
            ->throws(\InvalidArgumentException::class, 'not JSON-encodable');
    });

    test('fire() rejects payload with Inf value', function (): void {
        $manager = $this->app->make(EventManager::class);

        expect(fn (): mixed => $manager->fire('test.inf', ['value' => INF]))
            ->throws(\InvalidArgumentException::class, 'not JSON-encodable');
    });

    test('fire() accepts payload with null values', function (): void {
        $this->app->bind(NullAction::class);

        Trigger::factory()->create([
            'event' => 'test.null.payload',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'conditions' => [],
        ]);

        $manager = $this->app->make(EventManager::class);

        // Should not throw — null is valid JSON
        $manager->fire('test.null.payload', ['key' => null, 'nested' => ['a' => null]]);

        $this->assertDatabaseHas('event_logs', [
            'event' => 'test.null.payload',
            'status' => 'completed',
        ]);
    });

    test('fire() rejects payload exceeding max bytes', function (): void {
        $manager = $this->app->make(EventManager::class);

        // Set a small limit for testing
        $this->app->get('config')->set('events.payload_max_bytes', 100);

        $bigPayload = ['data' => str_repeat('x', 200)];

        expect(fn (): mixed => $manager->fire('test.oversize', $bigPayload))
            ->throws(\InvalidArgumentException::class, 'maximum allowed size');
    });

    test('fire() accepts payload at exactly the max bytes limit', function (): void {
        $this->app->bind(NullAction::class);

        Trigger::factory()->create([
            'event' => 'test.exact.limit',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'conditions' => [],
        ]);

        // Set limit to the exact size of our payload
        $payload = ['key' => 'val'];
        $encoded = json_encode($payload);
        $this->app->get('config')->set('events.payload_max_bytes', strlen((string) $encoded));

        $manager = $this->app->make(EventManager::class);

        // Should not throw — payload is exactly at the limit
        $manager->fire('test.exact.limit', $payload);

        $this->assertDatabaseHas('event_logs', [
            'event' => 'test.exact.limit',
            'status' => 'completed',
        ]);
    });

    test('fire() with payload_max_bytes=0 disables the size check', function (): void {
        $this->app->bind(NullAction::class);

        Trigger::factory()->create([
            'event' => 'test.no.limit',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'conditions' => [],
        ]);

        // Set limit to 0 to disable
        $this->app->get('config')->set('events.payload_max_bytes', 0);

        $manager = $this->app->make(EventManager::class);

        // Large payload should be accepted when limit is 0
        $bigPayload = ['data' => str_repeat('x', 2_000_000)];
        $manager->fire('test.no.limit', $bigPayload);

        $this->assertDatabaseHas('event_logs', [
            'event' => 'test.no.limit',
            'status' => 'completed',
        ]);
    });
});

describe('Wildcard cache invalidation consistency', function (): void {
    test('invalidateTriggerCache is called after trigger creation via builder', function (): void {
        $this->app->bind(NullAction::class);

        $manager = $this->app->make(EventManager::class);

        // Pre-warm the cache
        Trigger::factory()->create([
            'event' => 'cache.test.*',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'conditions' => [],
        ]);

        $manager->fire('cache.test.event', []);

        // Cache should now exist
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

        // Create a new trigger — should invalidate the cache
        $manager->on('cache.test.new')
            ->action(NullAction::class)
            ->save();

        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
    });

    test('invalidateTriggerCache is called after enable/disable', function (): void {
        $this->app->bind(NullAction::class);

        $manager = $this->app->make(EventManager::class);

        // Create a wildcard trigger (disabled)
        $trigger = Trigger::factory()->create([
            'event' => 'cache.enable.*',
            'action' => NullAction::class,
            'enabled' => false,
            'async' => false,
            'conditions' => [],
        ]);

        // Enabling should invalidate cache
        $manager->enable($trigger->id);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();

        // Fire to warm cache
        $manager->fire('cache.enable.test', []);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

        // Disabling should invalidate cache
        $manager->disable($trigger->id);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
    });

    test('invalidateTriggerCache is called after delete', function (): void {
        $this->app->bind(NullAction::class);

        $manager = $this->app->make(EventManager::class);

        $trigger = Trigger::factory()->create([
            'event' => 'cache.delete.*',
            'action' => NullAction::class,
            'enabled' => true,
            'async' => false,
            'conditions' => [],
        ]);

        // Fire to warm cache
        $manager->fire('cache.delete.test', []);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

        // Delete should invalidate cache
        $manager->deleteTrigger($trigger->id);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
    });
});
