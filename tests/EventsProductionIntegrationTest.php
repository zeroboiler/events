<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

describe('Events Production Integration', function (): void {
    describe('WildcardMatcher edge cases', function (): void {
        it('matches catch-all pattern against multi-segment events', function (): void {
            expect(WildcardMatcher::matches('*', 'order.placed.extra'))->toBeTrue();
            expect(WildcardMatcher::matches('**', 'order.placed.extra'))->toBeTrue();
        });

        it('rejects empty event name for catch-all patterns', function (): void {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('handles consecutive dots in event name', function (): void {
            expect(WildcardMatcher::matches('order..placed', 'order..placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order..placed'))->toBeFalse();
            expect(WildcardMatcher::matches('order.**', 'order..placed'))->toBeTrue();
        });

        it('handles trailing/leading dots', function (): void {
            expect(WildcardMatcher::matches('.order', '.order'))->toBeTrue();
            expect(WildcardMatcher::matches('order.', 'order.'))->toBeTrue();
            expect(WildcardMatcher::matches('*.', 'order.'))->toBeTrue();
            expect(WildcardMatcher::matches('.*', '.order'))->toBeTrue();
        });

        it('extracts wildcards correctly from single-segment patterns', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
                ->toBe(['profile']);
            expect(WildcardMatcher::extractWildcards('*.order.*', 'new.order.placed'))
                ->toBe(['new', 'placed']);
        });

        it('returns empty for cross-segment wildcard extraction', function (): void {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))
                ->toBe([]);
        });

        it('finds all matching patterns from a list', function (): void {
            $patterns = ['order.placed', 'order.*', 'user.*', '*.created'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
            expect($result)->toContain('order.placed');
            expect($result)->toContain('order.*');
            expect($result)->not->toContain('user.*');
            expect($result)->not->toContain('*.created');
        });
    });

    describe('DomainEvent value object', function (): void {
        it('creates event with auto-generated UUID and timestamp', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        it('serializes to array and reconstructs preserving identity', function (): void {
            $original = DomainEvent::occur('order.created', ['id' => 123]);
            $data = $original->toArray();

            expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);

            $reconstructed = DomainEvent::fromArray($data);

            expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
            expect($reconstructed->eventType)->toBe($original->eventType);
            expect($reconstructed->occurredAt->format(\DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
        });

        it('throws on reconstruction with missing eventType', function (): void {
            expect(fn (): mixed => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(\InvalidArgumentException::class, 'eventType is required');
        });

        it('falls back to fresh UUID/datetime on invalid data', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
                'occurredAt' => 'not-a-date',
                'payload' => 'not-an-array',
            ]);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe([]);
            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        it('converts to string with expected format', function (): void {
            $event = DomainEvent::occur('test.event');
            $str = (string) $event;

            expect($str)->toStartWith('DomainEvent[test.event]');
            expect($str)->toContain('id=');
            expect($str)->toContain('at=');
        });
    });

    describe('ConditionEngine all operators', function (): void {
        it('evaluates all comparison operators', function (): void {
            $engine = new ConditionEngine;
            $payload = ['amount' => 100, 'status' => 'active', 'tags' => ['urgent', 'review'], 'name' => 'admin@zeroboiler.com'];

            // Numeric comparisons
            expect($engine->matches(['amount' => ['>', 50]], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['>=', 100]], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['<', 200]], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['<=', 100]], $payload))->toBeTrue();

            // Equality
            expect($engine->matches(['status' => 'active'], $payload))->toBeTrue();
            expect($engine->matches(['status' => ['=', 'active']], $payload))->toBeTrue();
            expect($engine->matches(['status' => ['===', 'active']], $payload))->toBeTrue();
            expect($engine->matches(['status' => ['!=', 'inactive']], $payload))->toBeTrue();
            expect($engine->matches(['status' => ['!==', 100]], $payload))->toBeTrue();

            // Array operators
            expect($engine->matches(['tags' => ['contains', 'urgent']], $payload))->toBeTrue();
            expect($engine->matches(['tags' => ['not_contains', 'spam']], $payload))->toBeTrue();
            expect($engine->matches(['status' => ['in', ['active', 'pending']]], $payload))->toBeTrue();
            expect($engine->matches(['status' => ['not_in', ['disabled', 'deleted']]], $payload))->toBeTrue();

            // Range
            expect($engine->matches(['amount' => ['between', [50, 200]]], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['between', [200, 50]]], $payload))->toBeTrue(); // inverted range

            // Null checks
            expect($engine->matches(['missing' => ['null']], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['not_null']], $payload))->toBeTrue();

            // Empty checks
            expect($engine->matches(['missing' => ['empty']], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['not_empty']], $payload))->toBeTrue();

            // String operators
            expect($engine->matches(['name' => ['starts_with', 'admin@']], $payload))->toBeTrue();
            expect($engine->matches(['name' => ['ends_with', '.com']], $payload))->toBeTrue();
            expect($engine->matches(['status' => ['matches', '/^act/']], $payload))->toBeTrue();
        });

        it('returns false for non-matching conditions', function (): void {
            $engine = new ConditionEngine;
            $payload = ['amount' => 100];

            expect($engine->matches(['amount' => ['>', 200]], $payload))->toBeFalse();
            expect($engine->matches(['amount' => ['in', [1, 2, 3]]], $payload))->toBeFalse();
        });

        it('handles nested dot-notation fields', function (): void {
            $engine = new ConditionEngine;
            $payload = ['user' => ['role' => 'admin', 'email' => 'test@example.com']];

            expect($engine->matches(['user.role' => 'admin'], $payload))->toBeTrue();
            expect($engine->matches(['user.email' => ['ends_with', '.com']], $payload))->toBeTrue();
            expect($engine->matches(['user.role' => ['null']], $payload))->toBeFalse();
        });

        it('rejects ReDoS-prone regex patterns', function (): void {
            $engine = new ConditionEngine;
            $payload = ['code' => 'aaa'];

            // Nested quantifiers should be rejected
            expect($engine->matches(['code' => ['matches', '/(a+)+b/']], $payload))->toBeFalse();

            // Overly long pattern should be rejected
            $longPattern = '/^' . str_repeat('a', 501) . '$/';
            expect($engine->matches(['code' => ['matches', $longPattern]], $payload))->toBeFalse();
        });
    });

    describe('EventManager CRUD operations', function (): void {
        it('creates and retrieves a trigger', function (): void {
            $em = self::$app->make(EventManager::class);

            $trigger = $em->on('test.crud.create')
                ->name('CRUD Test Trigger')
                ->action(NullAction::class)
                ->priority(5)
                ->save();

            expect($trigger)->toBeInstanceOf(Trigger::class);
            expect($trigger->event)->toBe('test.crud.create');
            expect($trigger->name)->toBe('CRUD Test Trigger');
            expect($trigger->priority)->toBe(5);
            expect($trigger->enabled)->toBeTrue();

            $retrieved = $em->getTrigger($trigger->id);
            expect($retrieved)->not->toBeNull();
            expect($retrieved->id)->toBe($trigger->id);
        });

        it('enables and disables triggers with cache invalidation', function (): void {
            $em = self::$app->make(EventManager::class);

            $trigger = $em->on('test.crud.toggle')
                ->name('Toggle Test')
                ->action(NullAction::class)
                ->save();

            expect($em->disable($trigger->id))->toBeTrue();
            $fresh = Trigger::find($trigger->id);
            expect($fresh->enabled)->toBeFalse();

            expect($em->enable($trigger->id))->toBeTrue();
            $fresh = Trigger::find($trigger->id);
            expect($fresh->enabled)->toBeTrue();
        });

        it('deletes a trigger', function (): void {
            $em = self::$app->make(EventManager::class);

            $trigger = $em->on('test.crud.delete')
                ->name('Delete Test')
                ->action(NullAction::class)
                ->save();

            expect($em->deleteTrigger($trigger->id))->toBeTrue();
            expect($em->getTrigger($trigger->id))->toBeNull();
            expect($em->deleteTrigger($trigger->id))->toBeFalse();
        });

        it('lists triggers with filtering', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('test.list.a')->name('List A')->action(NullAction::class)->save();
            $em->on('test.list.b')->name('List B')->action(NullAction::class)->save();

            $all = $em->listTriggers('test.list.*');
            expect($all->count())->toBeGreaterThanOrEqual(2);

            $enabled = $em->listTriggers(null, true);
            $enabled->each(fn (Trigger $t): bool => expect($t->enabled)->toBeTrue());
        });
    });

    describe('EventManager fire and execute', function (): void {
        it('fires a synchronous trigger and creates an event log', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('test.fire.sync')
                ->name('Sync Fire Test')
                ->action(NullAction::class)
                ->save();

            $em->fire('test.fire.sync', ['key' => 'value']);

            $logs = EventLog::where('event', 'test.fire.sync')->get();
            expect($logs->count())->toBe(1);
            expect($logs->first()->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($logs->first()->duration_ms)->not->toBeNull();
        });

        it('fires only triggers with matching conditions', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('test.fire.condition')
                ->name('Condition Match')
                ->action(NullAction::class)
                ->when(['amount' => ['>', 100]])
                ->save();

            // Should not fire (amount < 100)
            $em->fire('test.fire.condition', ['amount' => 50]);
            expect(EventLog::where('event', 'test.fire.condition')->count())->toBe(0);

            // Should fire (amount > 100)
            $em->fire('test.fire.condition', ['amount' => 200]);
            expect(EventLog::where('event', 'test.fire.condition')->count())->toBe(1);
        });

        it('rejects empty event name', function (): void {
            $em = self::$app->make(EventManager::class);

            expect(fn (): mixed => $em->fire(''))
                ->toThrow(\InvalidArgumentException::class, 'cannot be empty');
        });

        it('rejects oversized payloads', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('test.fire.oversized')
                ->name('Oversized Test')
                ->action(NullAction::class)
                ->save();

            // Set a very low limit for testing
            $em->setEnabled(true);

            // Create a payload that exceeds 1MB
            $bigPayload = ['data' => str_repeat('x', 2_000_000)];
            expect(fn (): mixed => $em->fire('test.fire.oversized', $bigPayload))
                ->toThrow(\InvalidArgumentException::class, 'maximum allowed size');
        });

        it('silently returns when globally disabled', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('test.fire.disabled')
                ->name('Disabled Test')
                ->action(NullAction::class)
                ->save();

            $em->setEnabled(false);
            $em->fire('test.fire.disabled', ['key' => 'value']);

            expect(EventLog::where('event', 'test.fire.disabled')->count())->toBe(0);
            $em->setEnabled(true);
        });
    });

    describe('Event history and statistics', function (): void {
        it('returns event history with filtering', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('test.history.event')
                ->name('History Test')
                ->action(NullAction::class)
                ->save();

            $em->fire('test.history.event', ['n' => 1]);
            $em->fire('test.history.event', ['n' => 2]);

            $history = $em->getEventHistory('test.history.event');
            expect($history->count())->toBe(2);
        });

        it('returns aggregate statistics', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('test.stats.event')
                ->name('Stats Test')
                ->action(NullAction::class)
                ->save();

            $em->fire('test.stats.event');

            $stats = $em->getStats();
            expect($stats)->toHaveKey('total_logs');
            expect($stats)->toHaveKey('total_triggers');
            expect($stats)->toHaveKey('active_triggers');
            expect($stats)->toHaveKey('completed');
            expect($stats)->toHaveKey('failed');
            expect($stats)->toHaveKey('success_rate');
            expect($stats['total_logs'])->toBeInt();
            expect($stats['total_logs'])->toBeGreaterThan(0);
        });

        it('purges old event logs', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('test.purge.event')
                ->name('Purge Test')
                ->action(NullAction::class)
                ->save();

            $em->fire('test.purge.event');

            // Purge logs older than now (should delete all completed logs)
            $deleted = $em->purgeLogs(\Illuminate\Support\Carbon::now()->addSecond());
            expect($deleted)->toBeGreaterThanOrEqual(1);
        });
    });

    describe('Subscription lifecycle', function (): void {
        it('creates and retrieves a subscription', function (): void {
            $em = self::$app->make(EventManager::class);

            $sub = $em->subscribe('test.sub.event', 'https://example.com/webhook')
                ->withSecret('whsec_test_secret_key_12345')
                ->priority(10)
                ->save();

            expect($sub)->toBeInstanceOf(Subscription::class);
            expect($sub->event)->toBe('test.sub.event');
            expect($sub->url)->toBe('https://example.com/webhook');
            expect($sub->secret)->toBe('whsec_test_secret_key_12345');
            expect($sub->active)->toBeTrue();

            $retrieved = $em->getSubscription($sub->id);
            expect($retrieved)->not->toBeNull();
        });

        it('unsubscribes and cleans up trigger', function (): void {
            $em = self::$app->make(EventManager::class);

            $sub = $em->subscribe('test.unsub.event', 'https://example.com/unsub')
                ->save();

            expect($em->unsubscribe($sub->id))->toBeTrue();
            expect($em->getSubscription($sub->id))->toBeNull();
        });

        it('rejects non-HTTP webhook URLs', function (): void {
            $em = self::$app->make(EventManager::class);

            expect(fn (): mixed => $em->subscribe('test.sub.ftp', 'ftp://evil.com/')->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');

            expect(fn (): mixed => $em->subscribe('test.sub.file', 'file:///etc/passwd')->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
        });

        it('lists subscriptions with filtering', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->subscribe('test.sub.list.a', 'https://a.example.com')->save();
            $em->subscribe('test.sub.list.b', 'https://b.example.com')->save();

            $subs = $em->listSubscriptions('test.sub.list.*');
            expect($subs->count())->toBeGreaterThanOrEqual(2);

            $active = $em->listSubscriptions(null, activeOnly: true);
            $active->each(fn (Subscription $s): bool => expect($s->active)->toBeTrue());
        });
    });

    describe('Exception hierarchy', function (): void {
        it('all leaf exceptions extend EventException', function (): void {
            $actionEx = new \ZeroBoiler\Events\Exceptions\ActionResolutionException('TestAction', 'not found');
            $conditionEx = new \ZeroBoiler\Events\Exceptions\ConditionEvaluationException('field', 'reason');
            $subEx = new \ZeroBoiler\Events\Exceptions\SubscriptionException('sub failed');
            $triggerEx = new \ZeroBoiler\Events\Exceptions\TriggerNotFoundException('id-123');

            expect($actionEx)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
            expect($actionEx)->toBeInstanceOf(\RuntimeException::class);
            expect($conditionEx)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
            expect($subEx)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
            expect($triggerEx)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);

            expect($actionEx->getMessage())->toContain('TestAction');
            expect($triggerEx->getMessage())->toContain('id-123');
        });
    });

    describe('fireModel with non-standard models', function (): void {
        it('handles object with attributesToArray method', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('App\\Models\\TestModel.created')
                ->name('Model Test')
                ->action(NullAction::class)
                ->save();

            $model = new class
            {
                public function attributesToArray(): array
                {
                    return ['id' => 1, 'name' => 'Test'];
                }
            };

            $em->fireModel('App\\Models\\TestModel', 'created', $model);

            $logs = EventLog::where('event', 'App\\Models\\TestModel.created')->get();
            expect($logs->count())->toBe(1);
            $payload = $logs->first()->payload;
            expect($payload['id'])->toBe(1);
            expect($payload['name'])->toBe('Test');
            expect($payload['model_class'])->toBe('App\\Models\\TestModel');
            expect($payload['action'])->toBe('created');
        });

        it('handles object with only toArray method', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('App\\Models\\ToArrayModel.updated')
                ->name('ToArray Test')
                ->action(NullAction::class)
                ->save();

            $model = new class
            {
                public function toArray(): array
                {
                    return ['status' => 'active'];
                }
            };

            $em->fireModel('App\\Models\\ToArrayModel', 'updated', $model);

            $logs = EventLog::where('event', 'App\\Models\\ToArrayModel.updated')->get();
            expect($logs->count())->toBe(1);
            expect($logs->first()->payload['status'])->toBe('active');
        });

        it('handles plain object without serialization methods', function (): void {
            $em = self::$app->make(EventManager::class);

            $em->on('App\\Models\\PlainModel.deleted')
                ->name('Plain Model Test')
                ->action(NullAction::class)
                ->save();

            $model = new \stdClass();
            $em->fireModel('App\\Models\\PlainModel', 'deleted', $model);

            $logs = EventLog::where('event', 'App\\Models\\PlainModel.deleted')->get();
            expect($logs->count())->toBe(1);
            expect($logs->first()->payload['model_class'])->toBe('App\\Models\\PlainModel');
        });

        it('rejects empty model class', function (): void {
            $em = self::$app->make(EventManager::class);

            expect(fn (): mixed => $em->fireModel('', 'created', new \stdClass()))
                ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
        });

        it('rejects empty model action', function (): void {
            $em = self::$app->make(EventManager::class);

            expect(fn (): mixed => $em->fireModel('App\\Model', '', new \stdClass()))
                ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
        });
    });
});

/**
 * No-op action for testing synchronous dispatch.
 */
final class NullAction implements Triggerable
{
    public function handle(array $payload): void
    {
        // Intentionally empty — used for testing dispatch flow
    }
}
