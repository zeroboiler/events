<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 129 Production Audit', function (): void {
    describe('ConditionEngine — empty condition operator', function (): void {
        it('returns false for empty array operator (no operator string)', function (): void {
            $engine = new ConditionEngine;

            // Empty array as condition value → no operator at index 0
            $result = $engine->matches(['field' => []], ['field' => 'value']);
            expect($result)->toBeFalse();
        });

        it('returns false for operator array with empty string operator', function (): void {
            $engine = new ConditionEngine;

            // Empty string as operator → falls to default => false
            $result = $engine->matches(['field' => ['', 'value']], ['field' => 'value']);
            expect($result)->toBeFalse();
        });

        it('returns false for unknown operator', function (): void {
            $engine = new ConditionEngine;

            // Unknown operator → falls to default => false
            $result = $engine->matches(['field' => ['unknown_op', 'value']], ['field' => 'value']);
            expect($result)->toBeFalse();
        });
    });

    describe('ConditionEngine — numeric comparison edge cases', function (): void {
        it('comparison operators return false when actual is null', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['>', 0]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['>=', 0]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<=', 100]], ['amount' => null]))->toBeFalse();
        });

        it('comparison operators return false when value is null', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['>', null]], ['amount' => 50]))->toBeFalse();
            expect($engine->matches(['amount' => ['>=', null]], ['amount' => 50]))->toBeFalse();
        });

        it('comparison operators return false when actual is non-numeric', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['>', 0]], ['amount' => 'abc']))->toBeFalse();
            expect($engine->matches(['amount' => ['<', 100]], ['amount' => 'not-a-number']))->toBeFalse();
        });

        it('comparison operators return false when value is non-numeric', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['>', 'abc']], ['amount' => 50]))->toBeFalse();
        });

        it('between returns false when actual is null', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['between', [1, 10]]], ['amount' => null]))->toBeFalse();
        });

        it('between returns false when actual is non-numeric', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['between', [1, 10]]], ['amount' => 'abc']))->toBeFalse();
        });

        it('between returns false for non-array range value', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['between', 'not-an-array']], ['amount' => 5]))->toBeFalse();
        });

        it('between returns false for array range with wrong length', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['between', [1]]], ['amount' => 5]))->toBeFalse();
            expect($engine->matches(['amount' => ['between', [1, 5, 10]]], ['amount' => 5]))->toBeFalse();
        });

        it('between returns false when min/max are non-numeric', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['between', ['a', 'b']]], ['amount' => 5]))->toBeFalse();
        });
    });

    describe('ConditionEngine — string operator edge cases', function (): void {
        it('starts_with returns false when actual is non-string', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['starts_with', 'pre']], ['field' => 123]))->toBeFalse();
            expect($engine->matches(['field' => ['starts_with', 'pre']], ['field' => null]))->toBeFalse();
        });

        it('ends_with returns false when actual is non-string', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['ends_with', 'fix']], ['field' => 123]))->toBeFalse();
            expect($engine->matches(['field' => ['ends_with', 'fix']], ['field' => null]))->toBeFalse();
        });

        it('matches returns false when actual is non-string', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['code' => ['matches', '/^[A-Z]+$/']], ['code' => 123]))->toBeFalse();
            expect($engine->matches(['code' => ['matches', '/^[A-Z]+$/']], ['code' => null]))->toBeFalse();
        });

        it('matches returns false when pattern is non-string', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['code' => ['matches', 123]], ['code' => 'ABC']))->toBeFalse();
        });

        it('not_contains works with string values', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['text' => ['not_contains', 'spam']], ['text' => 'hello world']))->toBeTrue();
            expect($engine->matches(['text' => ['not_contains', 'spam']], ['text' => 'hello spam world']))->toBeFalse();
        });

        it('not_in works with array values', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['role' => ['not_in', ['admin', 'root']]], ['role' => 'user']))->toBeTrue();
            expect($engine->matches(['role' => ['not_in', ['admin', 'root']]], ['role' => 'admin']))->toBeFalse();
        });

        it('not_in returns false when value is null', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['role' => ['not_in', ['admin']]], ['role' => null]))->toBeFalse();
        });

        it('in returns false when value is null', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['role' => ['in', ['admin']]], ['role' => null]))->toBeFalse();
        });

        it('empty operator checks for empty values', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['empty']], ['field' => '']))->toBeTrue();
            expect($engine->matches(['field' => ['empty']], ['field' => 0]))->toBeTrue();
            expect($engine->matches(['field' => ['empty']], ['field' => null]))->toBeTrue();
            expect($engine->matches(['field' => ['empty']], ['field' => 'value']))->toBeFalse();
            expect($engine->matches(['field' => ['empty']], ['field' => []]))->toBeTrue();
        });

        it('not_empty operator checks for non-empty values', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['not_empty']], ['field' => 'value']))->toBeTrue();
            expect($engine->matches(['field' => ['not_empty']], ['field' => '']))->toBeFalse();
            expect($engine->matches(['field' => ['not_empty']], ['field' => null]))->toBeFalse();
        });
    });

    describe('WildcardMatcher — cross-segment pattern edge cases', function (): void {
        it('** matches deep nested events', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed.item.created'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.a.b.c.d.e.f'))->toBeTrue();
        });

        it('*.* matches exactly two-segment events', function (): void {
            expect(WildcardMatcher::matches('*.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('*.*', 'order'))->toBeFalse();
            expect(WildcardMatcher::matches('*.*', 'order.placed.extra'))->toBeFalse();
        });

        it('multiple * in pattern', function (): void {
            expect(WildcardMatcher::matches('*.*.*', 'a.b.c'))->toBeTrue();
            expect(WildcardMatcher::matches('*.*.*', 'a.b'))->toBeFalse();
            expect(WildcardMatcher::matches('*.*.*', 'a.b.c.d'))->toBeFalse();
        });

        it('pattern with literal dot and asterisk', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('empty event returns false for non-catch-all patterns', function (): void {
            expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
            expect(WildcardMatcher::matches('order.**', ''))->toBeFalse();
            expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
        });
    });

    describe('WildcardMatcher::extractWildcards — edge cases', function (): void {
        it('returns empty array for ** patterns', function (): void {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
        });

        it('returns empty when segment count differs', function (): void {
            expect(WildcardMatcher::extractWildcards('order.*', 'order.placed.extra'))->toBe([]);
        });

        it('returns empty when pattern does not match', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.deleted'))->toBe([]);
        });

        it('extracts multiple wildcards', function (): void {
            $result = WildcardMatcher::extractWildcards('*.order.*', 'new.order.created');
            expect($result)->toBe(['new', 'created']);
        });
    });

    describe('DomainEvent — fromArray edge cases', function (): void {
        it('throws when eventType is empty string', function (): void {
            expect(fn () => \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when eventType is missing', function (): void {
            expect(fn () => \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['payload' => []]))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('falls back to fresh UUID for invalid UUID string', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-valid-uuid',
            ]);
            expect($event->eventId)->not->toBeNull();
            // Should have generated a fresh UUID
            expect($event->eventId->toString())->toBeString();
        });

        it('falls back to now for invalid datetime string', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-valid-date',
            ]);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        it('handles extra fields in data array gracefully', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => ['key' => 'value'],
                'extraField' => 'ignored',
            ]);
            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
        });

        it('handles numeric eventType', function (): void {
            // Should work with non-empty string
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => '123',
            ]);
            expect($event->eventType)->toBe('123');
        });

        it('handles payload as non-array gracefully', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => 'not-an-array',
            ]);
            expect($event->payload)->toBe([]);
        });

        it('handles eventId as non-string gracefully', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 12345,
            ]);
            // Non-string eventId should generate fresh UUID
            expect($event->eventId)->not->toBeNull();
        });
    });

    describe('EventLog — model edge cases', function (): void {
        it('auto-generates UUID on create if id is empty', function (): void {
            $log = EventLog::factory()->make(['id' => '']);
            $log->save();
            expect($log->id)->not->toBeEmpty();
            $log->delete();
        });

        it('preserves explicitly set UUID', function (): void {
            $uuid = (string) \Illuminate\Support\Str::uuid();
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->create([
                'id' => $uuid,
                'trigger_id' => $trigger->id,
            ]);
            expect($log->id)->toBe($uuid);
        });

        it('markAsCompleted updates status and duration', function (): void {
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->create([
                'trigger_id' => $trigger->id,
                'status' => EventLog::STATUS_DISPATCHED,
            ]);

            $log->markAsCompleted(150);
            $log->refresh();

            expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($log->duration_ms)->toBe(150);
        });

        it('markAsFailed updates status and error', function (): void {
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->create([
                'trigger_id' => $trigger->id,
                'status' => EventLog::STATUS_DISPATCHED,
            ]);

            $log->markAsFailed('Connection timeout');
            $log->refresh();

            expect($log->status)->toBe(EventLog::STATUS_FAILED);
            expect($log->error)->toBe('Connection timeout');
        });
    });

    describe('Trigger — model edge cases', function (): void {
        it('auto-generates UUID on create', function (): void {
            $trigger = Trigger::factory()->make(['id' => '']);
            $trigger->save();
            expect($trigger->id)->not->toBeEmpty();
            $trigger->delete();
        });

        it('scopeEnabled filters correctly', function (): void {
            Trigger::factory()->create(['enabled' => true]);
            Trigger::factory()->create(['enabled' => false]);

            expect(Trigger::enabled()->count())->toBe(1);
        });

        it('scopeAsync filters correctly', function (): void {
            Trigger::factory()->create(['async' => true]);
            Trigger::factory()->create(['async' => false]);

            expect(Trigger::async()->count())->toBe(1);
        });

        it('orderByPriority sorts descending', function (): void {
            Trigger::factory()->create(['priority' => 10]);
            Trigger::factory()->create(['priority' => 50]);
            Trigger::factory()->create(['priority' => 30]);

            $triggers = Trigger::orderByPriority()->get();
            expect($triggers[0]->priority)->toBeGreaterThanOrEqual($triggers[1]->priority);
            expect($triggers[1]->priority)->toBeGreaterThanOrEqual($triggers[2]->priority);
        });
    });

    describe('EventManager — fire validation edge cases', function (): void {
        it('fire throws on zero-string event', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fire('0', ['key' => 'value']))
                ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty.');
        });

        it('fireModel throws on empty model class', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fireModel('', 'created', new \stdClass))
                ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty.');
        });

        it('fireModel throws on empty action', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fireModel('App\\Models\\Order', '', new \stdClass))
                ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty.');
        });

        it('fireModel throws on zero-string model class', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fireModel('0', 'created', new \stdClass))
                ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty.');
        });

        it('fireModel throws on zero-string action', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fireModel('App\\Models\\Order', '0', new \stdClass))
                ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty.');
        });
    });

    describe('ConditionEngine — strictEquals edge cases', function (): void {
        it('strict equals same type returns correct result', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => 'value'], ['field' => 'value']))->toBeTrue();
            expect($engine->matches(['field' => 'value'], ['field' => 'different']))->toBeFalse();
            expect($engine->matches(['field' => 42], ['field' => 42]))->toBeTrue();
            expect($engine->matches(['field' => 42], ['field' => 43]))->toBeFalse();
            expect($engine->matches(['field' => true], ['field' => true]))->toBeTrue();
            expect($engine->matches(['field' => true], ['field' => false]))->toBeFalse();
        });

        it('strict equals different types compares as strings if both scalar', function (): void {
            $engine = new ConditionEngine;

            // int 42 === string "42" via string comparison
            expect($engine->matches(['field' => '42'], ['field' => 42]))->toBeTrue();
        });

        it('strict equals array vs string returns false', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['value']], ['field' => 'value']))->toBeFalse();
            expect($engine->matches(['field' => 'value'], ['field' => ['value']]))->toBeFalse();
        });

        it('strict inequality operators', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['!=', 'value']], ['field' => 'different']))->toBeTrue();
            expect($engine->matches(['field' => ['!=', 'value']], ['field' => 'value']))->toBeFalse();
            expect($engine->matches(['field' => ['!==', 42]], ['field' => '42']))->toBeTrue();
            expect($engine->matches(['field' => ['!==', 42]], ['field' => 42]))->toBeFalse();
        });
    });

    describe('Config consistency', function (): void {
        it('all config keys referenced in code exist in published config', function (): void {
            $config = include __DIR__.'/../config/events.php';
            $expectedKeys = [
                'table_names.triggers',
                'table_names.event_logs',
                'table_names.subscriptions',
                'queue.connection',
                'queue.queue',
                'retry.tries',
                'retry.backoff',
                'retention.days',
                'retention.include_pending',
                'retention.schedule_cron',
                'subscriptions.auto_generate_secret',
                'subscriptions.max_failures',
                'subscriptions.timeout',
                'subscriptions.signature_algorithm',
                'subscriptions.cleanup_cron',
                'disabled',
                'wildcard_cache_ttl',
            ];

            foreach ($expectedKeys as $key) {
                $parts = explode('.', $key);
                $current = $config;
                $exists = true;
                foreach ($parts as $part) {
                    if (! is_array($current) || ! array_key_exists($part, $current)) {
                        $exists = false;
                        break;
                    }
                    $current = $current[$part];
                }
                expect($exists)->toBeTrue("Config key 'events.{$key}' is referenced in code but missing from config/events.php");
            }
        });
    });
});
