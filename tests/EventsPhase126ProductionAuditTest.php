<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

// Load test action classes
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('ConditionEngine: type coercion edge cases', function (): void {
    test('float vs int comparison with > operator returns true when appropriate', function (): void {
        $engine = new ConditionEngine;

        // float actual vs int expected
        expect($engine->matches(['amount' => ['>', 100]], ['amount' => 100.5]))->toBeTrue()
            ->and($engine->matches(['amount' => ['>', 100]], ['amount' => 99.9]))->toBeFalse();
    });

    test('string numeric comparison works with = operator via strictEquals', function (): void {
        $engine = new ConditionEngine;

        // Same type comparison
        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue()
            ->and($engine->matches(['status' => 'active'], ['status' => 'pending']))->toBeFalse();
    });

    test('cross-type numeric comparison via strictEquals falls back to string', function (): void {
        $engine = new ConditionEngine;

        // int vs string that look the same
        expect($engine->matches(['count' => 5], ['count' => '5']))->toBeTrue()
            ->and($engine->matches(['count' => 5], ['count' => '10']))->toBeFalse();
    });

    test('array vs scalar strictEquals returns false', function (): void {
        $engine = new ConditionEngine;

        expect($engine->matches(['tags' => 'urgent'], ['tags' => ['urgent']]))->toBeFalse();
    });

    test('null comparison with not_null operator', function (): void {
        $engine = new ConditionEngine;

        expect($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => null]))->toBeFalse()
            ->and($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => '2024-01-01']))->toBeTrue();
    });

    test('empty operator handles various empty values', function (): void {
        $engine = new ConditionEngine;

        expect($engine->matches(['notes' => ['empty']], ['notes' => null]))->toBeTrue()
            ->and($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue()
            ->and($engine->matches(['notes' => ['empty']], ['notes' => 0]))->toBeTrue()
            ->and($engine->matches(['notes' => ['empty']], ['notes' => 'hello']))->toBeFalse();
    });

    test('not_empty operator', function (): void {
        $engine = new ConditionEngine;

        expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'hello']))->toBeTrue()
            ->and($engine->matches(['notes' => ['not_empty']], ['notes' => null]))->toBeFalse()
            ->and($engine->matches(['notes' => ['not_empty']], ['notes' => '']))->toBeFalse();
    });

    test('deeply nested dot notation (4 levels)', function (): void {
        $engine = new ConditionEngine;

        $payload = [
            'user' => [
                'profile' => [
                    'settings' => [
                        'theme' => 'dark',
                    ],
                ],
            ],
        ];

        expect($engine->matches(['user.profile.settings.theme' => 'dark'], $payload))->toBeTrue()
            ->and($engine->matches(['user.profile.settings.theme' => 'light'], $payload))->toBeFalse();
    });

    test('between operator with inverted range auto-normalizes', function (): void {
        $engine = new ConditionEngine;

        // [100, 50] should auto-normalize to [50, 100]
        expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 75]))->toBeTrue()
            ->and($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 25]))->toBeFalse()
            ->and($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 120]))->toBeFalse();
    });

    test('matches operator with invalid regex pattern returns false', function (): void {
        $engine = new ConditionEngine;

        // Invalid regex
        expect($engine->matches(['code' => ['matches', '/[invalid/']], ['code' => 'test']))->toBeFalse();
    });

    test('matches operator with overly long pattern returns false', function (): void {
        $engine = new ConditionEngine;

        $longPattern = '/^' . str_repeat('a', 600) . '$/';
        expect($engine->matches(['code' => ['matches', $longPattern]], ['code' => str_repeat('a', 600)]))->toBeFalse();
    });
});

describe('WildcardMatcher: edge cases', function (): void {
    test('exact match without wildcards', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('empty pattern does not match empty event', function (): void {
        expect(WildcardMatcher::matches('', ''))->toBeFalse();
    });

    test('single-segment wildcard within longer pattern', function (): void {
        expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue()
            ->and(WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse()
            ->and(WildcardMatcher::matches('*.order.*', 'user.order.created.extra'))->toBeFalse();
    });

    test('findMatchingPatterns returns empty array for no matches', function (): void {
        $result = WildcardMatcher::findMatchingPatterns(['order.*', 'user.*'], 'invoice.paid');
        expect($result)->toBe([]);
    });

    test('findMatchingPatterns returns all matching patterns', function (): void {
        $result = WildcardMatcher::findMatchingPatterns(['order.*', 'user.*', '*.paid'], 'order.paid');
        expect($result)->toEqual(['order.*', '*.paid']);
    });

    test('extractWildcards returns empty for patterns with **', function (): void {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.shipped');
        expect($result)->toBe([]);
    });

    test('extractWildcards with multiple single wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('*.order.*', 'user.order.created');
        expect($result)->toEqual(['user', 'created']);
    });

    test('pattern with regex special characters still works', function (): void {
        expect(WildcardMatcher::matches('order.+', 'order.+'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.+', 'order.A'))->toBeFalse();
    });
});

describe('DomainEvent: edge cases', function (): void {
    test('fromArray with missing eventType throws', function (): void {
        expect(fn (): DomainEvent => DomainEvent::fromArray([]))
            ->toThrow(InvalidArgumentException::class, 'eventType is required');
    });

    test('fromArray with invalid UUID falls back to fresh', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-uuid',
            'payload' => ['key' => 'value'],
        ]);

        expect($event->eventType)->toBe('test.event')
            ->and($event->payload)->toBe(['key' => 'value'])
            ->and($event->eventId)->not->toBeNull(); // Fresh UUID generated
    });

    test('fromArray with invalid date falls back to now', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-date',
        ]);

        expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
    });

    test('fromArray preserves extra fields in payload', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => ['key' => 'value', 'extra' => 'data'],
            'unknownField' => 'ignored',
        ]);

        // Extra fields at root level are ignored, payload is intact
        expect($event->payload)->toBe(['key' => 'value', 'extra' => 'data']);
    });

    test('roundtrip toArray → fromArray preserves identity', function (): void {
        $original = DomainEvent::occur('test.event', ['data' => 42]);
        $restored = DomainEvent::fromArray($original->toArray());

        expect($restored->eventId->toString())->toBe($original->eventId->toString())
            ->and($restored->eventType)->toBe($original->eventType)
            ->and($restored->payload)->toBe($original->payload)
            ->and($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
    });

    test('all readonly properties are accessible', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);

        expect($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class)
            ->and($event->eventType)->toBe('test.event')
            ->and($event->payload)->toBe(['key' => 'value'])
            ->and($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
    });
});

describe('EventManager: cache invalidation', function (): void {
    test('invalidateTriggerCache clears wildcard cache', function (): void {
        EventManager::invalidateTriggerCache();

        $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
        expect($cached)->toBeNull();
    });

    test('creating wildcard trigger then invalidating cache', function (): void {
        Trigger::factory()->create([
            'event' => 'order.*',
            'action' => SendOrderNotification::class,
            'enabled' => true,
        ]);

        EventManager::invalidateTriggerCache();

        // Next fire should rebuild the cache
        EventManager::fire('order.placed', ['order_id' => 1]);

        $logs = EventLog::where('event', 'order.placed')->get();
        expect($logs)->toHaveCount(1);
    });
});

describe('EventManager: global disable', function (): void {
    test('fire returns silently when disabled via config', function (): void {
        config(['events.disabled' => true]);

        Trigger::factory()->create([
            'event' => 'test.event',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManager::fire('test.event', ['data' => 1]);

        expect(EventLog::count())->toBe(0);
    });

    test('setEnabled(false) disables globally', function (): void {
        EventManager::setEnabled(false);

        expect(EventManager::isDisabled())->toBeTrue();

        EventManager::setEnabled(true);
        expect(EventManager::isDisabled())->toBeFalse();
    });
});

describe('EventManager: fire validation', function (): void {
    test('fire throws on empty string event', function (): void {
        expect(fn (): mixed => EventManager::fire(''))
            ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty');
    });

    test('fire throws on zero string event', function (): void {
        expect(fn (): mixed => EventManager::fire('0'))
            ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty');
    });

    test('fireModel throws on empty model class', function (): void {
        expect(fn (): mixed => EventManager::fireModel('', 'created', new stdClass))
            ->toThrow(InvalidArgumentException::class, 'Model class name cannot be empty');
    });

    test('fireModel throws on empty action', function (): void {
        expect(fn (): mixed => EventManager::fireModel('App\Models\Order', '', new stdClass))
            ->toThrow(InvalidArgumentException::class, 'Model action cannot be empty');
    });
});

describe('Subscription: edge cases', function (): void {
    test('signPayload returns empty string for null secret', function (): void {
        $sub = Subscription::factory()->create([
            'secret' => null,
        ]);

        expect($sub->signPayload('{"test": true}'))->toBe('');
    });

    test('signPayload returns empty string for empty secret', function (): void {
        $sub = Subscription::factory()->create([
            'secret' => '',
        ]);

        expect($sub->signPayload('{"test": true}'))->toBe('');
    });

    test('hasExceededFailures uses config default', function (): void {
        $sub = Subscription::factory()->create([
            'failure_count' => 10,
        ]);

        config(['events.subscriptions.max_failures' => 10]);
        expect($sub->hasExceededFailures())->toBeTrue();

        config(['events.subscriptions.max_failures' => 15]);
        expect($sub->hasExceededFailures())->toBeFalse();
    });

    test('recordDelivery increments delivery_count and sets last_fired_at', function (): void {
        $sub = Subscription::factory()->create([
            'delivery_count' => 0,
            'last_fired_at' => null,
        ]);

        $sub->recordDelivery();
        $sub->refresh();

        expect($sub->delivery_count)->toBe(1)
            ->and($sub->last_fired_at)->not->toBeNull();
    });

    test('resetFailures sets failure_count to 0', function (): void {
        $sub = Subscription::factory()->create([
            'failure_count' => 5,
        ]);

        $sub->resetFailures();
        $sub->refresh();

        expect($sub->failure_count)->toBe(0);
    });
});

describe('DispatchTriggerJob: constructor config', function (): void {
    test('constructor reads config values correctly', function (): void {
        config([
            'events.retry.tries' => 5,
            'events.retry.backoff' => '10,20,30',
            'events.queue.queue' => 'custom-queue',
            'events.queue.connection' => 'redis',
        ]);

        $job = new DispatchTriggerJob('trigger-id', 'test.event', ['key' => 'value']);

        expect($job->tries)->toBe(5)
            ->and($job->queue)->toBe('custom-queue')
            ->and($job->connection)->toBe('redis')
            ->and($job->backoff)->toBe([10, 20, 30]);
    });

    test('constructor handles array backoff config', function (): void {
        config(['events.retry.backoff' => [30, 60, 120]]);

        $job = new DispatchTriggerJob('trigger-id', 'test.event', []);
        expect($job->backoff)->toBe([30, 60, 120]);
    });

    test('constructor defaults when config is missing', function (): void {
        config([
            'events.retry.tries' => null,
            'events.retry.backoff' => null,
            'events.queue.queue' => null,
            'events.queue.connection' => null,
        ]);

        $job = new DispatchTriggerJob('trigger-id', 'test.event', []);

        expect($job->tries)->toBe(3)
            ->and($job->queue)->toBe('default')
            ->and($job->connection)->toBeNull()
            ->and($job->backoff)->toBe([60, 300, 900]);
    });
});
