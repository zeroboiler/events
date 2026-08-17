<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;


beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
    Cache::forget('zeroboiler:events:enabled_wildcard_triggers');
});

describe('ConditionEngine — ReDoS protection', function (): void {
    test('rejects regex patterns exceeding max length', function (): void {
        $engine = app(ConditionEngine::class);

        $longPattern = '/'.str_repeat('a', 600).'/';
        $result = $engine->matches(
            ['code' => ['matches', $longPattern]],
            ['code' => 'aaa'],
        );

        expect($result)->toBeFalse();
    });

    test('rejects regex patterns with nested quantifiers', function (): void {
        $engine = app(ConditionEngine::class);

        $result = $engine->matches(
            ['code' => ['matches', '/(a+)+b/']],
            ['code' => 'aaab'],
        );

        expect($result)->toBeFalse();
    });

    test('accepts safe regex patterns', function (): void {
        $engine = app(ConditionEngine::class);

        $result = $engine->matches(
            ['code' => ['matches', '/^[A-Z]{3}-\d{4}$/']],
            ['code' => 'ABC-1234'],
        );

        expect($result)->toBeTrue();
    });

    test('regex matches returns false on non-matching subject', function (): void {
        $engine = app(ConditionEngine::class);

        $result = $engine->matches(
            ['code' => ['matches', '/^[A-Z]+$/']],
            ['code' => 'abc'],
        );

        expect($result)->toBeFalse();
    });

    test('regex matches returns false when actual is not string', function (): void {
        $engine = app(ConditionEngine::class);

        $result = $engine->matches(
            ['count' => ['matches', '/^\d+$/']],
            ['count' => 123],
        );

        expect($result)->toBeFalse();
    });
});

describe('ConditionEngine — not_contains operator', function (): void {
    test('not_contains returns true when value is absent from string', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['bio' => ['not_contains', 'spam']],
            ['bio' => 'hello world'],
        ))->toBeTrue();
    });

    test('not_contains returns false when value is present in string', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['bio' => ['not_contains', 'world']],
            ['bio' => 'hello world'],
        ))->toBeFalse();
    });

    test('not_contains returns true when value is absent from array', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['tags' => ['not_contains', 'urgent']],
            ['tags' => ['low', 'medium']],
        ))->toBeTrue();
    });

    test('not_contains returns false when value is present in array', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['tags' => ['not_contains', 'urgent']],
            ['tags' => ['low', 'urgent']],
        ))->toBeFalse();
    });
});

describe('ConditionEngine — not_empty operator', function (): void {
    test('not_empty returns true for non-empty string', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['name' => ['not_empty']],
            ['name' => 'hello'],
        ))->toBeTrue();
    });

    test('not_empty returns true for non-empty array', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['items' => ['not_empty']],
            ['items' => ['a']],
        ))->toBeTrue();
    });

    test('not_empty returns false for empty string', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['name' => ['not_empty']],
            ['name' => ''],
        ))->toBeFalse();
    });

    test('not_empty returns false for empty array', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['items' => ['not_empty']],
            ['items' => []],
        ))->toBeFalse();
    });
});

describe('WildcardMatcher — edge cases', function (): void {
    test('empty pattern does not match any event', function (): void {
        expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
    });

    test('empty event does not match catch-all pattern', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('exact match with no wildcards', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('single segment wildcard matches exactly one segment', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    });

    test('double segment wildcard matches across segments', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    });

    test('findMatchingPatterns returns empty for no matches', function (): void {
        $result = WildcardMatcher::findMatchingPatterns(['user.*', 'order.*'], 'product.created');

        expect($result)->toBe([]);
    });

    test('findMatchingPatterns returns all matching patterns', function (): void {
        $result = WildcardMatcher::findMatchingPatterns(['*', 'order.*', 'user.*'], 'order.placed');

        expect($result)->toBe(['*', 'order.*']);
    });

    test('extractWildcards returns empty for cross-segment patterns', function (): void {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        expect($result)->toBe([]);
    });

    test('extractWildcards extracts single-segment values', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.admin.created');

        expect($result)->toBe(['admin']);
    });

    test('extractWildcards returns empty when segment count differs', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.admin.role.created');

        expect($result)->toBe([]);
    });
});

describe('DomainEvent — fromArray edge cases', function (): void {
    test('fromArray with missing eventType creates empty event', function (): void {
        $event = DomainEvent::fromArray(['payload' => ['key' => 'value']]);

        expect($event->eventType)->toBe('');
        expect($event->payload)->toBe(['key' => 'value']);
    });

    test('fromArray with invalid UUID generates fresh one', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-uuid',
        ]);

        expect($event->eventId->toString())->not->toBe('not-a-uuid');
    });

    test('fromArray with invalid occurredAt uses current time', function (): void {
        $before = new \DateTimeImmutable();
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-date',
        ]);
        $after = new \DateTimeImmutable();

        expect($event->occurredAt)->toBeGreaterThanOrEqual($before);
        expect($event->occurredAt)->toBeLessThanOrEqual($after);
    });

    test('fromArray preserves valid eventId and occurredAt', function (): void {
        $uuid = \Ramsey\Uuid\Uuid::uuid4();
        $time = new \DateTimeImmutable('2025-01-15T10:30:00+00:00');

        $event = DomainEvent::fromArray([
            'eventType' => 'order.placed',
            'eventId' => $uuid->toString(),
            'occurredAt' => $time->format(\DateTimeImmutable::ATOM),
            'payload' => ['order_id' => 42],
        ]);

        expect($event->eventId->toString())->toBe($uuid->toString());
        expect($event->occurredAt)->toEqual($time);
        expect($event->payload)->toBe(['order_id' => 42]);
    });

    test('toArray and fromArray roundtrip preserves all fields', function (): void {
        $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
        $restored = DomainEvent::fromArray($original->toArray());

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
        expect($restored->occurredAt)->toEqual($original->occurredAt);
    });
});

describe('EventManager — wildcard cache invalidation on save', function (): void {
    test('saving a wildcard trigger populates cache on next fire', function (): void {
        $manager = app(EventManager::class);

        $manager->on('order.*')
            ->action('ZeroBoiler\Events\Tests\Actions\LogOrderEvent')
            ->save();

        // Cache should be empty before first fire
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();

        EventManagerFacade::fire('order.placed', []);

        // After fire, cache should be populated
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();
    });

    test('disabling a wildcard trigger invalidates cache', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'order.*',
            'action' => 'ZeroBoiler\Events\Tests\Actions\LogOrderEvent',
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('order.placed', []);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

        EventManagerFacade::disable($trigger->id);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
    });

    test('enabling a wildcard trigger invalidates cache', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'order.*',
            'action' => 'ZeroBoiler\Events\Tests\Actions\LogOrderEvent',
            'enabled' => false,
        ]);

        // Fire to populate cache (trigger won't match because disabled)
        EventManagerFacade::fire('order.placed', []);

        EventManagerFacade::enable($trigger->id);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
    });
});

describe('Subscription model — signPayload edge cases', function (): void {
    test('signPayload returns empty string for null secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => null]);

        expect($sub->signPayload('{"test":true}'))->toBe('');
    });

    test('signPayload returns empty string for empty secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => '']);

        expect($sub->signPayload('{"test":true}'))->toBe('');
    });

    test('signPayload is deterministic', function (): void {
        $sub = Subscription::factory()->create(['secret' => 'test-secret-key']);

        $sig1 = $sub->signPayload('payload');
        $sig2 = $sub->signPayload('payload');

        expect($sig1)->toBe($sig2);
    });

    test('signPayload produces different signatures for different payloads', function (): void {
        $sub = Subscription::factory()->create(['secret' => 'test-secret-key']);

        $sig1 = $sub->signPayload('payload-a');
        $sig2 = $sub->signPayload('payload-b');

        expect($sig1)->not->toBe($sig2);
    });
});

describe('EventLog model — markAsCompleted and markAsFailed', function (): void {
    test('markAsCompleted sets status and duration', function (): void {
        $trigger = Trigger::factory()->create();
        $log = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_DISPATCHED,
        ]);

        $log->markAsCompleted(250);

        expect($log->fresh()->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->fresh()->duration_ms)->toBe(250);
    });

    test('markAsFailed sets status and error message', function (): void {
        $trigger = Trigger::factory()->create();
        $log = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_DISPATCHED,
        ]);

        $log->markAsFailed('Connection timeout');

        expect($log->fresh()->status)->toBe(EventLog::STATUS_FAILED)
            ->and($log->fresh()->error)->toBe('Connection timeout');
    });
});

describe('ConditionEngineContract binding', function (): void {
    test('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
        $contract = app(ConditionEngineContract::class);
        $concrete = app(ConditionEngine::class);

        expect($contract)->toBeInstanceOf(ConditionEngine::class)
            ->and($contract)->toBe($concrete);
    });
});
