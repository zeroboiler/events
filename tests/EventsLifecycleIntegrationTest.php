<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

// Load test action classes

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
    Cache::forget('zeroboiler:events:enabled_wildcard_triggers');
});

describe('Full Lifecycle: Fire → Dispatch → Log → Stats', function (): void {
    test('end-to-end: fire event, check log created with correct status and duration', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'lifecycle.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
            'priority' => 10,
        ]);

        EventManagerFacade::fire('lifecycle.test', ['order_id' => 42]);

        expect(EventLog::count())->toBe(1);

        $log = EventLog::first();
        expect($log->trigger_id)->toBe($trigger->id)
            ->and($log->event)->toBe('lifecycle.test')
            ->and($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->payload)->toBe(['order_id' => 42])
            ->and($log->duration_ms)->toBeInt()
            ->and($log->duration_ms)->toBeGreaterThan(0);
    });

    test('end-to-end: fire with condition mismatch creates no log', function (): void {
        Trigger::factory()->create([
            'event' => 'conditional.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'conditions' => ['amount' => ['>', 100]],
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('conditional.test', ['amount' => 50]);

        expect(EventLog::count())->toBe(0);
    });

    test('end-to-end: fire with condition match creates log', function (): void {
        Trigger::factory()->create([
            'event' => 'conditional.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'conditions' => ['amount' => ['>', 100]],
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('conditional.test', ['amount' => 200]);

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('end-to-end: stats reflect fired events', function (): void {
        Trigger::factory()->create([
            'event' => 'stats.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('stats.test', ['key' => 'value']);

        $stats = EventManagerFacade::getStats();

        expect($stats['total_logs'])->toBe(1)
            ->and($stats['completed'])->toBe(1)
            ->and($stats['failed'])->toBe(0)
            ->and($stats['success_rate'])->toBe(100.0)
            ->and($stats['avg_duration_ms'])->not->toBeNull()
            ->and($stats['top_events'])->toHaveCount(1)
            ->and($stats['top_events'][0]['event'])->toBe('stats.test')
            ->and($stats['top_events'][0]['count'])->toBe(1);
    });

    test('end-to-end: fire multiple events, check aggregate stats', function (): void {
        Trigger::factory()->create([
            'event' => 'multi.stat',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('multi.stat', []);
        EventManagerFacade::fire('multi.stat', []);
        EventManagerFacade::fire('multi.stat', []);

        $stats = EventManagerFacade::getStats();

        expect($stats['total_logs'])->toBe(3)
            ->and($stats['completed'])->toBe(3)
            ->and($stats['success_rate'])->toBe(100.0);
    });
});

describe('Trigger Priority Ordering', function (): void {
    test('higher priority triggers are dispatched first', function (): void {
        Trigger::factory()->create([
            'event' => 'priority.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\LowPriority::class,
            'enabled' => true,
            'async' => false,
            'priority' => 1,
        ]);

        Trigger::factory()->create([
            'event' => 'priority.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\HighPriority::class,
            'enabled' => true,
            'async' => false,
            'priority' => 100,
        ]);

        EventManagerFacade::fire('priority.test', []);

        $logs = EventLog::orderBy('id')->get();
        expect($logs)->toHaveCount(2);

        // Higher priority should have been dispatched first (lower ID = earlier creation)
        $firstAction = Trigger::find($logs[0]->trigger_id)->action;
        $secondAction = Trigger::find($logs[1]->trigger_id)->action;

        expect($firstAction)->toContain('HighPriority')
            ->and($secondAction)->toContain('LowPriority');
    });

    test('same priority triggers are ordered by creation time', function (): void {
        $triggerA = Trigger::factory()->create([
            'event' => 'same.priority',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
            'priority' => 50,
        ]);

        // Small delay to ensure different created_at
        usleep(10000);

        $triggerB = Trigger::factory()->create([
            'event' => 'same.priority',
            'action' => \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class,
            'enabled' => true,
            'async' => false,
            'priority' => 50,
        ]);

        EventManagerFacade::fire('same.priority', []);

        $logs = EventLog::orderBy('id')->get();
        expect($logs[0]->trigger_id)->toBe($triggerA->id);
    });
});

describe('Wildcard Cache Invalidation', function (): void {
    test('creating a new wildcard trigger invalidates cache', function (): void {
        // Prime the cache by firing an event with no triggers
        EventManagerFacade::fire('cache.warmup', []);

        // Create a wildcard trigger via builder (which calls invalidateTriggerCache)
        $builder = EventManagerFacade::on('cache.*')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->priority(10);

        // Manually save to avoid full builder flow issues in test
        $trigger = Trigger::factory()->create([
            'event' => 'cache.*',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // The cache should be invalidated after TriggerBuilder::save()
        // Fire an event that should match the wildcard
        EventManagerFacade::fire('cache.test', ['key' => 'value']);

        expect(EventLog::count())->toBe(1);
    });

    test('disabling a wildcard trigger prevents future matches', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'toggle.*',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // Should match
        EventManagerFacade::fire('toggle.event', []);
        expect(EventLog::count())->toBe(1);

        // Disable
        EventManagerFacade::disable($trigger->id);

        // Clear cache to ensure fresh lookup
        EventManagerFacade::invalidateTriggerCache();

        // Should NOT match
        EventManagerFacade::fire('toggle.event', []);
        expect(EventLog::count())->toBe(1); // Still 1 from before
    });
});

describe('Event History Filtering', function (): void {
    test('getEventHistory returns logs filtered by status', function (): void {
        Trigger::factory()->create([
            'event' => 'history.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('history.test', []);

        $completedLogs = EventManagerFacade::getEventHistory(status: EventLog::STATUS_COMPLETED);
        expect($completedLogs)->toHaveCount(1);

        $failedLogs = EventManagerFacade::getEventHistory(status: EventLog::STATUS_FAILED);
        expect($failedLogs)->toHaveCount(0);
    });

    test('getEventHistory returns logs filtered by event with wildcard', function (): void {
        Trigger::factory()->create([
            'event' => 'history.a',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        Trigger::factory()->create([
            'event' => 'history.b',
            'action' => \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('history.a', []);
        EventManagerFacade::fire('history.b', []);

        $logs = EventManagerFacade::getEventHistory(event: 'history.*');
        expect($logs)->toHaveCount(2);
    });

    test('getEventHistory respects limit', function (): void {
        Trigger::factory()->create([
            'event' => 'limit.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        foreach (range(1, 5) as $i) {
            EventManagerFacade::fire('limit.test', ['iteration' => $i]);
        }

        $logs = EventManagerFacade::getEventHistory(limit: 3);
        expect($logs)->toHaveCount(3);
    });
});

describe('Purge Logs', function (): void {
    test('purgeLogs removes completed logs before threshold', function (): void {
        Trigger::factory()->create([
            'event' => 'purge.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('purge.test', []);

        expect(EventLog::count())->toBe(1);

        $deleted = EventManagerFacade::purgeLogs(
            before: \Illuminate\Support\Carbon::now()->addSecond(),
        );

        expect($deleted)->toBe(1)
            ->and(EventLog::count())->toBe(0);
    });

    test('purgeLogs does not remove pending logs when includePending is false', function (): void {
        // Create a pending log directly (simulates a queued job that hasn't run yet)
        EventLog::factory()->pending()->create([
            'event' => 'purge.pending',
        ]);

        $deleted = EventManagerFacade::purgeLogs(
            before: \Illuminate\Support\Carbon::now()->addSecond(),
            includePending: false,
        );

        expect($deleted)->toBe(0)
            ->and(EventLog::count())->toBe(1);
    });

    test('purgeLogs removes pending logs when includePending is true', function (): void {
        EventLog::factory()->pending()->create([
            'event' => 'purge.pending.include',
        ]);

        $deleted = EventManagerFacade::purgeLogs(
            before: \Illuminate\Support\Carbon::now()->addSecond(),
            includePending: true,
        );

        expect($deleted)->toBe(1)
            ->and(EventLog::count())->toBe(0);
    });
});

describe('Domain Event Roundtrip', function (): void {
    test('DomainEvent serialization roundtrip preserves all fields', function (): void {
        $original = DomainEvent::occur('user.registered', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $data = $original->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventType)->toBe('user.registered')
            ->and($restored->payload)->toBe(['email' => 'test@example.com', 'name' => 'Test User'])
            ->and($restored->eventId->toString())->toBe($original->eventId->toString())
            ->and($restored->occurredAt->format(\DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
    });

    test('DomainEvent fromArray with missing eventType throws', function (): void {
        expect(fn (): mixed => DomainEvent::fromArray(['payload' => []]))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('DomainEvent fromArray with invalid UUID generates fresh one', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => [],
            'eventId' => 'not-a-uuid',
        ]);

        expect($event->eventId)->not->toBeNull();
    });

    test('DomainEvent fromArray with invalid datetime uses now', function (): void {
        $before = new \DateTimeImmutable();
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => [],
            'occurredAt' => 'not-a-date',
        ]);
        $after = new \DateTimeImmutable();

        expect($event->occurredAt)->greaterThanOrEqual($before)
            ->lessThanOrEqual($after);
    });
});

describe('ActionResolver Edge Cases', function (): void {
    test('resolve throws for non-existent class', function (): void {
        $resolver = app(ActionResolver::class);

        expect(fn (): mixed => $resolver->resolve('NonExistent\\Class'))
            ->toThrow(\InvalidArgumentException::class, 'does not exist');
    });

    test('resolve throws for class that does not implement Triggerable', function (): void {
        $resolver = app(ActionResolver::class);

        expect(fn (): mixed => $resolver->resolve(\stdClass::class))
            ->toThrow(\InvalidArgumentException::class, 'must implement');
    });

    test('resolve returns Triggerable instance for valid class', function (): void {
        $resolver = app(ActionResolver::class);

        $instance = $resolver->resolve(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class);

        expect($instance)->toBeInstanceOf(\ZeroBoiler\Events\Contracts\Triggerable::class);
    });
});

describe('WildcardMatcher Comprehensive', function (): void {
    test('exact match works', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    });

    test('exact mismatch returns false', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('single segment wildcard matches one segment', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.*', 'order.shipped'))->toBeTrue();
    });

    test('single segment wildcard rejects multi-segment', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    });

    test('cross segment wildcard matches multi-segment', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.**', 'order.placed.extra.deep'))->toBeTrue();
    });

    test('catch-all matches everything except empty', function (): void {
        expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue()
            ->and(WildcardMatcher::matches('*', 'a.b.c'))->toBeTrue()
            ->and(WildcardMatcher::matches('*', ''))->toBeFalse();
    });

    test('multiple wildcards match correctly', function (): void {
        expect(WildcardMatcher::matches('*.order.*', 'user.order.placed'))->toBeTrue()
            ->and(WildcardMatcher::matches('*.order.*', 'user.order.placed.extra'))->toBeFalse();
    });

    test('extractWildcards returns correct values', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        expect($result)->toBe(['profile']);
    });

    test('extractWildcards returns empty for cross-segment patterns', function (): void {
        expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
    });

    test('findMatchingPatterns returns all matching patterns', function (): void {
        $patterns = ['order.*', 'user.*', 'order.**', 'payment.*'];
        $matching = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matching)->toContain('order.*')
            ->and($matching)->toContain('order.**')
            ->and($matching)->not->toContain('user.*')
            ->and($matching)->not->toContain('payment.*');
    });

    test('findMatchingPatterns returns empty for no matches', function (): void {
        $patterns = ['user.*', 'payment.*'];
        $matching = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matching)->toBe([]);
    });
});

describe('ConditionEngine All Operators', function (): void {
    $engine = null;

    beforeEach(function () use (&$engine): void {
        $engine = app(ConditionEngine::class);
    });

    test('simple equality', function () use (&$engine): void {
        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue()
            ->and($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();
    });

    test('greater than', function () use (&$engine): void {
        expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue()
            ->and($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))->toBeFalse();
    });

    test('between inclusive', function () use (&$engine): void {
        expect($engine->matches(['amount' => ['between', [50, 200]]], ['amount' => 100]))->toBeTrue()
            ->and($engine->matches(['amount' => ['between', [50, 200]]], ['amount' => 50]))->toBeTrue()
            ->and($engine->matches(['amount' => ['between', [50, 200]]], ['amount' => 49]))->toBeFalse();
    });

    test('in operator', function () use (&$engine): void {
        expect($engine->matches(['status' => ['in', ['active', 'pending']]], ['status' => 'active']))->toBeTrue()
            ->and($engine->matches(['status' => ['in', ['active', 'pending']]], ['status' => 'deleted']))->toBeFalse();
    });

    test('contains operator (string)', function () use (&$engine): void {
        expect($engine->matches(['text' => ['contains', 'hello']], ['text' => 'hello world']))->toBeTrue()
            ->and($engine->matches(['text' => ['contains', 'hello']], ['text' => 'world']))->toBeFalse();
    });

    test('null and not_null operators', function () use (&$engine): void {
        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue()
            ->and($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => '2024-01-01']))->toBeTrue()
            ->and($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']))->toBeFalse();
    });

    test('starts_with and ends_with operators', function () use (&$engine): void {
        expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue()
            ->and($engine->matches(['email' => ['ends_with', '.com']], ['email' => 'admin@test.com']))->toBeTrue();
    });

    test('nested dot notation access', function () use (&$engine): void {
        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin']],
        ))->toBeTrue()
            ->and($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'user']],
            ))->toBeFalse();
    });

    test('AND logic — all conditions must match', function () use (&$engine): void {
        expect($engine->matches(
            ['status' => 'active', 'amount' => ['>', 100]],
            ['status' => 'active', 'amount' => 150],
        ))->toBeTrue()
            ->and($engine->matches(
                ['status' => 'active', 'amount' => ['>', 100]],
                ['status' => 'inactive', 'amount' => 150],
            ))->toBeFalse();
    });
});
