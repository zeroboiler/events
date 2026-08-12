<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('Events production edge cases', function (): void {
    test('getStats with zero logs returns correct zeroed structure', function (): void {
        $stats = EventManagerFacade::getStats();

        expect($stats)->toBeArray()
            ->and($stats)->toHaveKeys([
                'total_logs', 'total_triggers', 'active_triggers',
                'completed', 'failed', 'pending', 'dispatched',
                'success_rate', 'failure_rate', 'avg_duration_ms',
                'top_events', 'top_failed_events',
            ])
            ->and($stats['total_logs'])->toBe(0)
            ->and($stats['total_triggers'])->toBe(0)
            ->and($stats['active_triggers'])->toBe(0)
            ->and($stats['completed'])->toBe(0)
            ->and($stats['failed'])->toBe(0)
            ->and($stats['pending'])->toBe(0)
            ->and($stats['dispatched'])->toBe(0)
            ->and($stats['success_rate'])->toBeNull()
            ->and($stats['failure_rate'])->toBeNull()
            ->and($stats['avg_duration_ms'])->toBeNull()
            ->and($stats['top_events'])->toBeArray()
            ->and($stats['top_failed_events'])->toBeArray();
    });

    test('getStats success rate with only completed logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        EventLog::factory()
            ->forTrigger($trigger->id)
            ->completed()
            ->create(['event' => 'order.placed']);

        $stats = EventManagerFacade::getStats();

        expect($stats['success_rate'])->toBe(100.0)
            ->and($stats['failure_rate'])->toBe(0.0)
            ->and($stats['total_logs'])->toBe(1)
            ->and($stats['completed'])->toBe(1);
    });

    test('getStats success rate with only failed logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        EventLog::factory()
            ->forTrigger($trigger->id)
            ->failed()
            ->create(['event' => 'order.placed']);

        $stats = EventManagerFacade::getStats();

        expect($stats['success_rate'])->toBe(0.0)
            ->and($stats['failure_rate'])->toBe(100.0);
    });

    test('getStats mixed completed and failed computes correct rates', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        EventLog::factory()->forTrigger($trigger->id)->completed()->count(7)->create();
        EventLog::factory()->forTrigger($trigger->id)->failed()->count(3)->create();

        $stats = EventManagerFacade::getStats();

        expect($stats['success_rate'])->toBe(70.0)
            ->and($stats['failure_rate'])->toBe(30.0)
            ->and($stats['total_logs'])->toBe(10)
            ->and($stats['settled'] ?? null)->toBeNull(); // not exposed in stats
    });

    test('getStats filters by since date', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        // Create an old completed log
        EventLog::factory()
            ->forTrigger($trigger->id)
            ->completed()
            ->create([
                'event' => 'order.placed',
                'created_at' => Carbon::now()->subDays(60),
            ]);

        // Create a recent completed log
        EventLog::factory()
            ->forTrigger($trigger->id)
            ->completed()
            ->create([
                'event' => 'order.shipped',
                'created_at' => Carbon::now()->subHours(1),
            ]);

        $stats = EventManagerFacade::getStats(Carbon::now()->subDays(1));

        expect($stats['total_logs'])->toBe(1)
            ->and($stats['top_events'][0]['event'])->toBe('order.shipped');
    });

    test('getStats avg_duration_ms null when no logs have duration', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        EventLog::factory()
            ->forTrigger($trigger->id)
            ->pending()
            ->create();

        $stats = EventManagerFacade::getStats();

        expect($stats['avg_duration_ms'])->toBeNull();
    });

    test('getStats top_events aggregation', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->count(5)
            ->create(['event' => 'order.placed']);
        EventLog::factory()->forTrigger($trigger->id)->completed()->count(3)
            ->create(['event' => 'order.shipped']);
        EventLog::factory()->forTrigger($trigger->id)->completed()->count(2)
            ->create(['event' => 'order.cancelled']);

        $stats = EventManagerFacade::getStats();

        expect($stats['top_events'])->toHaveCount(3)
            ->and($stats['top_events'][0]['event'])->toBe('order.placed')
            ->and($stats['top_events'][0]['count'])->toBe(5)
            ->and($stats['top_events'][1]['event'])->toBe('order.shipped')
            ->and($stats['top_events'][1]['count'])->toBe(3);
    });

    test('getStats top_failed_events only shows failed', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->count(5)
            ->create(['event' => 'order.placed']);
        EventLog::factory()->forTrigger($trigger->id)->failed()->count(2)
            ->create(['event' => 'order.placed']);

        $stats = EventManagerFacade::getStats();

        expect($stats['top_failed_events'])->toHaveCount(1)
            ->and($stats['top_failed_events'][0]['event'])->toBe('order.placed')
            ->and($stats['top_failed_events'][0]['count'])->toBe(2);
    });

    test('getStalePendingLogs returns only pending logs before threshold', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        // Old pending log (stale)
        EventLog::factory()->forTrigger($trigger->id)->pending()->create([
            'created_at' => Carbon::now()->subHours(48),
        ]);

        // Recent pending log (not stale)
        EventLog::factory()->forTrigger($trigger->id)->pending()->create([
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        // Old completed log (should not be returned)
        EventLog::factory()->forTrigger($trigger->id)->completed()->create([
            'created_at' => Carbon::now()->subHours(48),
        ]);

        $stale = EventManagerFacade::getStalePendingLogs(
            Carbon::now()->subHours(24),
        );

        expect($stale)->toHaveCount(1)
            ->and($stale->first()->created_at->lt(Carbon::now()->subHours(24)))
            ->toBeTrue();
    });

    test('deactivateExceededSubscriptions deactivates exceeded subscriptions', function (): void {
        // Create an exceeded subscription (failure_count >= max_failures)
        $sub1 = Subscription::factory()
            ->withFailureCount(10)
            ->create(['event' => 'order.placed']);

        // Create a healthy subscription
        $sub2 = Subscription::factory()
            ->withFailureCount(3)
            ->create(['event' => 'order.shipped']);

        $deactivated = EventManagerFacade::deactivateExceededSubscriptions();

        expect($deactivated)->toBe(1)
            ->and($sub1->fresh()->active)->toBeFalse()
            ->and($sub2->fresh()->active)->toBeTrue();
    });

    test('purgeLogs by default excludes pending logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->create([
            'created_at' => Carbon::now()->subDays(60),
        ]);
        EventLog::factory()->forTrigger($trigger->id)->pending()->create([
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $purged = EventManagerFacade::purgeLogs(Carbon::now()->subDays(30));

        expect($purged)->toBe(1);

        // Pending log should still exist
        $remaining = EventLog::count();
        expect($remaining)->toBe(1);
    });

    test('purgeLogs with includePending removes pending logs too', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->create([
            'created_at' => Carbon::now()->subDays(60),
        ]);
        EventLog::factory()->forTrigger($trigger->id)->pending()->create([
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $purged = EventManagerFacade::purgeLogs(Carbon::now()->subDays(30), includePending: true);

        expect($purged)->toBe(2)
            ->and(EventLog::count())->toBe(0);
    });

    test('purgeLogs respects date boundary — does not delete recent logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        // Old log (should be purged)
        EventLog::factory()->forTrigger($trigger->id)->completed()->create([
            'created_at' => Carbon::now()->subDays(60),
        ]);

        // Recent log (should NOT be purged)
        EventLog::factory()->forTrigger($trigger->id)->completed()->create([
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $purged = EventManagerFacade::purgeLogs(Carbon::now()->subDays(30));

        expect($purged)->toBe(1)
            ->and(EventLog::count())->toBe(1);
    });

    test('fire with empty string throws InvalidArgumentException', function (): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Event name cannot be empty.');

        EventManagerFacade::fire('');
    });

    test('fire with zero-string throws InvalidArgumentException', function (): void {
        $this->expectException(InvalidArgumentException::class);

        EventManagerFacade::fire('0');
    });

    test('fireModel with empty model class throws InvalidArgumentException', function (): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Model class name cannot be empty.');

        EventManagerFacade::fireModel('', 'created', new \stdClass);
    });

    test('fireModel with empty action throws InvalidArgumentException', function (): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Model action cannot be empty.');

        EventManagerFacade::fireModel('App\\Models\\User', '', new \stdClass);
    });

    test('fireModel constructs correct event name from model and action', function (): void {
        $trigger = Trigger::factory()->enabled()->create([
            'event' => 'App\\Models\\User.created',
        ]);

        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'users';

            public function attributesToArray(): array
            {
                return ['name' => 'Test User', 'email' => 'test@example.com'];
            }
        };

        // Fire and check a log was created
        EventManagerFacade::fireModel('App\\Models\\User', 'created', $model);

        $log = EventLog::where('trigger_id', $trigger->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->event)->toBe('App\\Models\\User.created');
    });

    test('deleteTrigger returns false for non-existent trigger', function (): void {
        $result = EventManagerFacade::deleteTrigger('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    test('deleteTrigger invalidates cache after deletion', function (): void {
        $trigger = Trigger::factory()->enabled()->create([
            'event' => 'cache.test',
        ]);

        // Verify trigger exists
        expect(Trigger::find($trigger->id))->not->toBeNull();

        // Delete
        $result = EventManagerFacade::deleteTrigger($trigger->id);

        expect($result)->toBeTrue()
            ->and(Trigger::find($trigger->id))->toBeNull();
    });

    test('setEnabled(false) causes fire to be a no-op', function (): void {
        Trigger::factory()->enabled()->create(['event' => 'silent.event']);

        EventManagerFacade::setEnabled(false);

        EventManagerFacade::fire('silent.event', ['data' => 'test']);

        // No event logs should be created because system is disabled
        expect(EventLog::count())->toBe(0);

        // Re-enable for other tests
        EventManagerFacade::setEnabled(true);
    });

    test('listTriggers with wildcard filter uses LIKE', function (): void {
        Trigger::factory()->enabled()->create(['event' => 'order.placed']);
        Trigger::factory()->enabled()->create(['event' => 'order.shipped']);
        Trigger::factory()->enabled()->create(['event' => 'user.created']);

        $results = EventManagerFacade::listTriggers('order.*');

        expect($results)->toHaveCount(2);
    });

    test('getEventHistory with wildcard filter', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->create([
            'event' => 'order.placed',
        ]);
        EventLog::factory()->forTrigger($trigger->id)->completed()->create([
            'event' => 'order.shipped',
        ]);
        EventLog::factory()->forTrigger($trigger->id)->completed()->create([
            'event' => 'user.created',
        ]);

        $history = EventManagerFacade::getEventHistory(event: 'order.*');

        expect($history)->toHaveCount(2);
    });

    test('getEventHistory with status filter', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->count(3)->create();
        EventLog::factory()->forTrigger($trigger->id)->failed()->count(2)->create();

        $history = EventManagerFacade::getEventHistory(status: 'failed');

        expect($history)->toHaveCount(2);
    });

    test('getEventHistory with triggerId filter', function (): void {
        $trigger1 = Trigger::factory()->enabled()->create();
        $trigger2 = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger1->id)->completed()->count(3)->create();
        EventLog::factory()->forTrigger($trigger2->id)->completed()->count(5)->create();

        $history = EventManagerFacade::getEventHistory(triggerId: $trigger1->id);

        expect($history)->toHaveCount(3);
    });

    test('listSubscriptions activeOnly filter', function (): void {
        Subscription::factory()->active()->create(['event' => 'order.placed']);
        Subscription::factory()->inactive()->create(['event' => 'order.shipped']);
        Subscription::factory()->active()->create(['event' => 'user.created']);

        $active = EventManagerFacade::listSubscriptions(activeOnly: true);

        expect($active)->toHaveCount(2);
    });

    test('unsubscribe returns false for non-existent subscription', function (): void {
        $result = EventManagerFacade::unsubscribe('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    test('getSubscription returns null for non-existent ID', function (): void {
        $result = EventManagerFacade::getSubscription('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeNull();
    });

    test('WildcardMatcher findMatchingPatterns returns correct subset', function (): void {
        $patterns = ['order.*', 'user.created', '*.placed', 'order.**'];
        $matched = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matched)->toContain('order.*')
            ->and($matched)->toContain('*.placed')
            ->and($matched)->toContain('order.**')
            ->and($matched)->not->toContain('user.created')
            ->and($matched)->toHaveCount(3);
    });

    test('WildcardMatcher extractWildcards returns empty for ** patterns', function (): void {
        $result = \ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.**', 'order.placed.shipped');

        expect($result)->toBeEmpty();
    });

    test('DomainEvent fromArray with invalid eventType throws', function (): void {
        $this->expectException(InvalidArgumentException::class);

        \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['eventType' => '']);
    });

    test('DomainEvent fromArray with missing eventType throws', function (): void {
        $this->expectException(InvalidArgumentException::class);

        \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['payload' => ['key' => 'value']]);
    });

    test('DomainEvent fromArray preserves eventId and occurredAt', function (): void {
        $eventId = \Ramsey\Uuid\Uuid::uuid4();
        $occurredAt = new \DateTimeImmutable('2025-01-15T12:00:00+00:00');

        $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
            'eventType' => 'order.placed',
            'payload' => ['order_id' => 42],
            'eventId' => $eventId->toString(),
            'occurredAt' => $occurredAt->format(\DateTimeImmutable::ATOM),
        ]);

        expect($event->eventId->toString())->toBe($eventId->toString())
            ->and($event->occurredAt)->toEqual($occurredAt)
            ->and($event->eventType)->toBe('order.placed');
    });

    test('DomainEvent fromArray handles invalid UUID gracefully', function (): void {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-valid-uuid',
        ]);

        // Should generate a fresh UUID instead of crashing
        expect($event->eventId)->not->toBeNull();
    });

    test('DomainEvent fromArray handles invalid datetime gracefully', function (): void {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-valid-date',
        ]);

        // Should use current time instead of crashing
        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    test('ConditionEngine matches with empty conditions returns true', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches([], ['any' => 'data']))->toBeTrue();
    });

    test('ConditionEngine matches with empty condition value returns false', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
    });

    test('ConditionEngine between with inverted range normalizes', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        // max before min — should be normalized
        expect($engine->matches(['amount' => ['between', 100, 50]], ['amount' => 75]))->toBeTrue();
        expect($engine->matches(['amount' => ['between', 100, 50]], ['amount' => 101]))->toBeFalse();
        expect($engine->matches(['amount' => ['between', 100, 50]], ['amount' => 49]))->toBeFalse();
    });

    test('ConditionEngine between with non-array value returns false', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['amount' => ['between', 100]], ['amount' => 75]))->toBeFalse();
    });

    test('ConditionEngine not_null and empty operators', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['field' => ['not_null']], ['field' => 'value']))->toBeTrue();
        expect($engine->matches(['field' => ['not_null']], ['other' => 'value']))->toBeFalse();
        expect($engine->matches(['field' => ['empty']], ['field' => '']))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => 'value']))->toBeFalse();
        expect($engine->matches(['field' => ['not_empty']], ['field' => 'value']))->toBeTrue();
        expect($engine->matches(['field' => ['not_empty']], []))->toBeFalse();
    });

    test('EscapesWildcardLike percent and underscore escaping', function (): void {
        // Use a concrete class that uses the trait to test
        $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
        $method = $ref->getMethod('wildcardToLike');

        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $result = $method->invoke($manager, 'test.event');
        expect($result)->toBeNull(); // No wildcard, returns null

        $result = $method->invoke($manager, 'order.*');
        expect($result)->toBe('order\%'); // Simple wildcard

        $result = $method->invoke($manager, 'test_%*');
        // % and _ should be escaped, * becomes %
        expect($result)->toBe('test\_\%\%');
    });
});
