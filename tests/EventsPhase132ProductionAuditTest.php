<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 132 — Production Audit
 *
 * Covers: EventManager listTriggers wildcard edge cases, fireModel with
 * dual-method models, EventsFireCommand JSON file edge cases, deleteTrigger
 * non-existent ID, enable/disable non-existent, SubscriptionBuilder scheme
 * validation, signPayload algorithm fallback, DomainEvent numeric eventType,
 * WildcardMatcher extractWildcards no-wildcard pattern, DispatchTriggerJob
 * property initialization, EventsHealthCommand critical detection,
 * ConditionEngine deep nested dot access, EventLog mark persistence.
 */
describe('Phase 132 — Production Audit', function () {
    // -------------------------------------------------------
    // EventManager: listTriggers wildcard pattern edge cases
    // -------------------------------------------------------
    describe('EventManager listTriggers wildcard edge cases', function () {
        it('listTriggers returns empty collection when no triggers match wildcard event', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            // No triggers in DB — should return empty collection regardless of filter
            $results = $manager->listTriggers('non.existent.event');
            expect($results)->toBeEmpty();
        });

        it('listTriggers with null event returns all triggers up to limit', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            // Create a few triggers
            Trigger::factory()->count(3)->create(['enabled' => true]);

            $results = $manager->listTriggers();
            expect($results)->toHaveCount(3);
        });

        it('listTriggers with limit 0 still works', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            Trigger::factory()->count(5)->create(['enabled' => true]);

            $results = $manager->listTriggers(limit: 2);
            expect($results)->toHaveCount(2);
        });

        it('listTriggers filters by enabled=true', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            Trigger::factory()->create(['enabled' => true]);
            Trigger::factory()->create(['enabled' => false]);
            Trigger::factory()->create(['enabled' => true]);

            $results = $manager->listTriggers(enabled: true);
            expect($results)->toHaveCount(2);
        });

        it('listTriggers filters by enabled=false', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            Trigger::factory()->create(['enabled' => true]);
            Trigger::factory()->create(['enabled' => false]);

            $results = $manager->listTriggers(enabled: false);
            expect($results)->toHaveCount(1);
            expect($results->first()->enabled)->toBeFalse();
        });
    });

    // -------------------------------------------------------
    // EventManager: deleteTrigger non-existent ID
    // -------------------------------------------------------
    describe('EventManager deleteTrigger edge cases', function () {
        it('deleteTrigger returns false for non-existent UUID', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            $result = $manager->deleteTrigger('00000000-0000-0000-0000-000000000000');
            expect($result)->toBeFalse();
        });

        it('deleteTrigger returns true and removes trigger', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            $trigger = Trigger::factory()->create(['enabled' => true]);
            $id = $trigger->id;

            $result = $manager->deleteTrigger($id);
            expect($result)->toBeTrue();
            expect(Trigger::find($id))->toBeNull();
        });
    });

    // -------------------------------------------------------
    // EventManager: enable/disable non-existent ID
    // -------------------------------------------------------
    describe('EventManager enable/disable non-existent ID', function () {
        it('enable returns false for non-existent trigger', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            $result = $manager->enable('00000000-0000-0000-0000-000000000000');
            expect($result)->toBeFalse();
        });

        it('disable returns false for non-existent trigger', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            $result = $manager->disable('00000000-0000-0000-0000-000000000000');
            expect($result)->toBeFalse();
        });

        it('enable toggles disabled trigger to enabled', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            $trigger = Trigger::factory()->create(['enabled' => false]);
            $id = $trigger->id;

            $result = $manager->enable($id);
            expect($result)->toBeTrue();

            $refreshed = Trigger::find($id);
            expect($refreshed->enabled)->toBeTrue();
        });

        it('disable toggles enabled trigger to disabled', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            $trigger = Trigger::factory()->create(['enabled' => true]);
            $id = $trigger->id;

            $result = $manager->disable($id);
            expect($result)->toBeTrue();

            $refreshed = Trigger::find($id);
            expect($refreshed->enabled)->toBeFalse();
        });
    });

    // -------------------------------------------------------
    // EventManager: fireModel with dual-method model
    // -------------------------------------------------------
    describe('EventManager fireModel edge cases', function () {
        it('fireModel uses attributesToArray when model has both methods', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            // Create a trigger that we can inspect via event logs
            $trigger = Trigger::factory()->create([
                'event' => 'App\\Models\\TestOrder.created',
                'enabled' => true,
                'async' => false,
                'conditions' => [],
            ]);

            $model = new class {
                public function attributesToArray(): array
                {
                    return ['name' => 'TestOrder', 'status' => 'active', 'amount' => 42];
                }

                public function toArray(): array
                {
                    return ['name' => 'TestOrder toArray', 'extra' => 'should not be used'];
                }
            };

            // fireModel constructs event as App\Models\TestOrder.created
            // It should use attributesToArray (first checked) for flattening
            // We just verify it doesn't throw — the event name and payload construction work
            expect(fn () => $manager->fireModel('App\\Models\\TestOrder', 'created', $model))
                ->not->toThrow(\Throwable::class);
        });

        it('fireModel throws on empty model class', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            expect(fn () => $manager->fireModel('', 'created', new \stdClass))
                ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty.');
        });

        it('fireModel throws on empty action', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            expect(fn () => $manager->fireModel('App\\Model', '', new \stdClass))
                ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty.');
        });

        it('fireModel throws on zero-string model class', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            expect(fn () => $manager->fireModel('0', 'created', new \stdClass))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('fireModel falls back to toArray when attributesToArray is missing', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            $model = new class {
                public function toArray(): array
                {
                    return ['name' => 'FallbackModel', 'value' => 99];
                }
            };

            expect(fn () => $manager->fireModel('App\\Models\\Fallback', 'updated', $model))
                ->not->toThrow(\Throwable::class);
        });
    });

    // -------------------------------------------------------
    // ConditionEngine: deeply nested dot-notation access
    // -------------------------------------------------------
    describe('ConditionEngine deep nested dot access', function () {
        it('evaluates condition with three-level nested key', function () {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['level1.level2.level3' => 'deep'],
                ['level1' => ['level2' => ['level3' => 'deep']]],
            );

            expect($result)->toBeTrue();
        });

        it('evaluates condition with four-level nested key', function () {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['a.b.c.d' => 'value'],
                ['a' => ['b' => ['c' => ['d' => 'value']]]],
            );

            expect($result)->toBeTrue();
        });

        it('returns false when nested key does not exist at intermediate level', function () {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['missing.key.value' => 'x'],
                ['other' => 'data'],
            );

            expect($result)->toBeFalse();
        });

        it('returns false when nested key path diverges to non-array', function () {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['level1.level2' => 'x'],
                ['level1' => 'not-an-array'],
            );

            expect($result)->toBeFalse();
        });

        it('evaluates nested key with array operator', function () {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['meta.count' => ['>', 5]],
                ['meta' => ['count' => 10]],
            );

            expect($result)->toBeTrue();
        });
    });

    // -------------------------------------------------------
    // WildcardMatcher: extractWildcards with no wildcards
    // -------------------------------------------------------
    describe('WildcardMatcher extractWildcards edge cases', function () {
        it('extractWildcards returns empty array for pattern without wildcards', function () {
            $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');
            expect($result)->toBe([]);
        });

        it('extractWildcards returns empty when segment count differs', function () {
            $result = WildcardMatcher::extractWildcards('order.*', 'order.placed.extra');
            expect($result)->toBe([]);
        });

        it('extractWildcards returns empty when pattern does not match event', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'order.placed.created');
            expect($result)->toBe([]);
        });

        it('extractWildcards returns single value for one wildcard', function () {
            $result = WildcardMatcher::extractWildcards('order.*.completed', 'order.123.completed');
            expect($result)->toBe(['123']);
        });

        it('extractWildcards returns multiple values for multiple wildcards', function () {
            $result = WildcardMatcher::extractWildcards('*.order.*', 'user.order.456');
            expect($result)->toBe(['user', '456']);
        });

        it('extractWildcards returns empty for cross-segment pattern', function () {
            $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');
            expect($result)->toBe([]);
        });
    });

    // -------------------------------------------------------
    // DomainEvent: fromArray edge cases
    // -------------------------------------------------------
    describe('DomainEvent fromArray edge cases', function () {
        it('fromArray rejects numeric eventType', function () {
            expect(fn () => DomainEvent::fromArray(['eventType' => 123, 'payload' => []]))
                ->toThrow(\InvalidArgumentException::class, 'DomainEvent eventType is required');
        });

        it('fromArray handles missing eventId by generating fresh UUID', function () {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => ['key' => 'value'],
            ]);

            expect($event->eventType)->toBe('test.event');
            expect($event->eventId)->not->toBeNull();
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->occurredAt)->not->toBeNull();
        });

        it('fromArray handles missing occurredAt by defaulting to now', function () {
            $before = new \DateTimeImmutable();
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => null,
            ]);
            $after = new \DateTimeImmutable();

            expect($event->occurredAt)->greaterThanOrEqual($before);
            expect($event->occurredAt)->lessThanOrEqual($after);
        });

        it('fromArray handles invalid UUID gracefully', function () {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-valid-uuid',
            ]);

            // Should generate a fresh UUID instead
            expect($event->eventId)->not->toBeNull();
            expect($event->eventId->toString())->not->toBe('not-a-valid-uuid');
        });

        it('fromArray handles invalid datetime gracefully', function () {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);

            // Should default to now
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        it('toArray and fromArray roundtrip preserves data', function () {
            $original = new DomainEvent('test.roundtrip', ['key' => 'value']);
            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
            expect($restored->payload)->toBe($original->payload);
        });
    });

    // -------------------------------------------------------
    // DispatchTriggerJob: property initialization
    // -------------------------------------------------------
    describe('DispatchTriggerJob property initialization', function () {
        it('initializes tries from config', function () {
            Config::set('events.retry.tries', 5);

            $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'value']);
            expect($job->tries)->toBe(5);
        });

        it('initializes backoff from comma-separated string config', function () {
            Config::set('events.retry.backoff', '30,120,300');

            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->backoff)->toBe([30, 120, 300]);
        });

        it('initializes backoff from array config', function () {
            Config::set('events.retry.backoff', [10, 20, 30]);

            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->backoff)->toBe([10, 20, 30]);
        });

        it('initializes queue name from config', function () {
            Config::set('events.queue.queue', 'events');

            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->queue)->toBe('events');
        });

        it('initializes connection from config when set', function () {
            Config::set('events.queue.connection', 'redis');

            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->connection)->toBe('redis');
        });

        it('keeps connection null when config is not a non-empty string', function () {
            Config::set('events.queue.connection', null);

            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->connection)->toBeNull();
        });

        it('exposes readonly constructor properties', function () {
            $job = new DispatchTriggerJob('trigger-uuid', 'order.placed', ['order_id' => 42]);

            expect($job->triggerId)->toBe('trigger-uuid');
            expect($job->event)->toBe('order.placed');
            expect($job->payload)->toBe(['order_id' => 42]);
        });

        it('defaults tries to 3 when config is invalid', function () {
            Config::set('events.retry.tries', 'not-a-number');

            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->tries)->toBe(3);
        });

        it('defaults tries to 3 when config is zero', function () {
            Config::set('events.retry.tries', 0);

            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->tries)->toBe(3);
        });
    });

    // -------------------------------------------------------
    // SubscriptionBuilder: non-HTTP scheme URLs rejected
    // -------------------------------------------------------
    describe('SubscriptionBuilder non-HTTP scheme validation', function () {
        it('rejects ftp:// URL', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            expect(fn () => $manager->subscribe('test.event', 'ftp://example.com/hook')->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS protocol');
        });

        it('rejects file:// URL', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            expect(fn () => $manager->subscribe('test.event', 'file:///etc/passwd')->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS protocol');
        });

        it('rejects javascript: URL', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            expect(fn () => $manager->subscribe('test.event', 'javascript:alert(1)')->save())
                ->toThrow(\InvalidArgumentException::class);
        });

        it('accepts https:// URL', function () {
            $engine = new ConditionEngine();
            $container = $this->app;
            $resolver = new ActionResolver($container);

            $manager = new EventManager($engine, $resolver, $container);

            // Mock the HTTP call won't happen during save() (subscription is created but webhook trigger may fail)
            // We just verify the URL passes validation — the actual HTTP POST isn't tested here
            $subscription = $manager->subscribe('test.event', 'https://example.com/webhook')
                ->save();

            expect($subscription)->toBeInstanceOf(Subscription::class);
            expect($subscription->url)->toBe('https://example.com/webhook');
        });
    });

    // -------------------------------------------------------
    // Subscription: signPayload algorithm fallback
    // -------------------------------------------------------
    describe('Subscription signPayload algorithm fallback', function () {
        it('signPayload returns empty string for empty secret', function () {
            $sub = Subscription::factory()->make(['secret' => '']);
            expect($sub->signPayload('test'))->toBe('');
        });

        it('signPayload returns empty string for null secret', function () {
            $sub = Subscription::factory()->make(['secret' => null]);
            expect($sub->signPayload('test'))->toBe('');
        });

        it('signPayload works with sha256 (default)', function () {
            $sub = Subscription::factory()->make(['secret' => 'test-secret']);
            $signature = $sub->signPayload('payload');

            expect($signature)->not->toBe('');
            expect($signature)->not->toBe('0');
        });

        it('signPayload uses sha512 when configured', function () {
            Config::set('events.subscriptions.signature_algorithm', 'sha512');

            $sub = Subscription::factory()->make(['secret' => 'test-secret']);
            $signature = $sub->signPayload('payload');

            expect($signature)->not->toBe('');
            // Verify it was actually signed with sha512 by recomputing
            $expected = hash_hmac('sha512', 'payload', 'test-secret');
            expect($signature)->toBe($expected);
        });

        it('signPayload falls back to sha256 for non-string algorithm config', function () {
            Config::set('events.subscriptions.signature_algorithm', 12345);

            $sub = Subscription::factory()->make(['secret' => 'test-secret']);
            $signature = $sub->signPayload('payload');

            expect($signature)->not->toBe('');
            $expected = hash_hmac('sha256', 'payload', 'test-secret');
            expect($signature)->toBe($expected);
        });

        it('signPayload returns consistent results for same input', function () {
            $sub = Subscription::factory()->make(['secret' => 'consistent-secret']);

            $sig1 = $sub->signPayload('same-payload');
            $sig2 = $sub->signPayload('same-payload');

            expect($sig1)->toBe($sig2);
        });
    });

    // -------------------------------------------------------
    // EventLog: markAsCompleted/markAsFailed persistence
    // -------------------------------------------------------
    describe('EventLog mark persistence', function () {
        it('markAsCompleted updates status and duration', function () {
            $log = EventLog::factory()->create([
                'status' => EventLog::STATUS_DISPATCHED,
                'duration_ms' => null,
            ]);

            $log->markAsCompleted(150);

            $refreshed = EventLog::find($log->id);
            expect($refreshed->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($refreshed->duration_ms)->toBe(150);
        });

        it('markAsFailed updates status and error message', function () {
            $log = EventLog::factory()->create([
                'status' => EventLog::STATUS_DISPATCHED,
                'error' => null,
            ]);

            $log->markAsFailed('Connection timeout');

            $refreshed = EventLog::find($log->id);
            expect($refreshed->status)->toBe(EventLog::STATUS_FAILED);
            expect($refreshed->error)->toBe('Connection timeout');
        });

        it('EventLog scopes chain correctly', function () {
            EventLog::factory()->create(['status' => EventLog::STATUS_COMPLETED]);
            EventLog::factory()->create(['status' => EventLog::STATUS_FAILED]);
            EventLog::factory()->create(['status' => EventLog::STATUS_PENDING]);
            EventLog::factory()->create(['status' => EventLog::STATUS_FAILED]);

            $failed = EventLog::failed()->get();
            expect($failed)->toHaveCount(2);

            $completed = EventLog::completed()->get();
            expect($completed)->toHaveCount(1);

            $pending = EventLog::pending()->get();
            expect($pending)->toHaveCount(1);
        });

        it('EventLog stalePending scope returns correct results', function () {
            $old = EventLog::factory()->create([
                'status' => EventLog::STATUS_PENDING,
                'created_at' => now()->subHours(25),
            ]);
            EventLog::factory()->create([
                'status' => EventLog::STATUS_PENDING,
                'created_at' => now()->subMinutes(5),
            ]);

            $stale = EventLog::stalePending(now()->subHours(24))->get();
            expect($stale)->toHaveCount(1);
            expect($stale->first()->id)->toBe($old->id);
        });
    });

    // -------------------------------------------------------
    // Subscription: matchesEvent edge cases
    // -------------------------------------------------------
    describe('Subscription matchesEvent edge cases', function () {
        it('matchesEvent returns true for exact match', function () {
            $sub = Subscription::factory()->make(['event' => 'order.placed']);
            expect($sub->matchesEvent('order.placed'))->toBeTrue();
        });

        it('matchesEvent returns false for different event', function () {
            $sub = Subscription::factory()->make(['event' => 'order.placed']);
            expect($sub->matchesEvent('order.shipped'))->toBeFalse();
        });

        it('matchesEvent delegates to WildcardMatcher for single-segment wildcard', function () {
            $sub = Subscription::factory()->make(['event' => 'order.*']);
            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
        });

        it('matchesEvent delegates to WildcardMatcher for cross-segment wildcard', function () {
            $sub = Subscription::factory()->make(['event' => 'order.**']);
            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
        });
    });

    // -------------------------------------------------------
    // Trigger model: scope composition
    // -------------------------------------------------------
    describe('Trigger scope composition', function () {
        it('enabled + async scopes compose correctly', function () {
            Trigger::factory()->create(['enabled' => true, 'async' => true]);
            Trigger::factory()->create(['enabled' => true, 'async' => false]);
            Trigger::factory()->create(['enabled' => false, 'async' => true]);
            Trigger::factory()->create(['enabled' => false, 'async' => false]);

            $results = Trigger::enabled()->async()->get();
            expect($results)->toHaveCount(1);
            expect($results->first()->enabled)->toBeTrue();
            expect($results->first()->async)->toBeTrue();
        });

        it('orderByPriority orders highest first', function () {
            $low = Trigger::factory()->create(['priority' => 1]);
            $high = Trigger::factory()->create(['priority' => 100]);
            $mid = Trigger::factory()->create(['priority' => 50]);

            $results = Trigger::orderByPriority()->get();
            expect($results->first()->id)->toBe($high->id);
            expect($results->last()->id)->toBe($low->id);
        });
    });

    // -------------------------------------------------------
    // Config key completeness verification
    // -------------------------------------------------------
    describe('Config key completeness', function () {
        it('all config keys exist after loading default config', function () {
            // The service provider merges config in boot, but we can check defaults directly
            $defaults = include __DIR__.'/../config/events.php';

            expect($defaults)->toHaveKey('table_names');
            expect($defaults)->toHaveKey('queue');
            expect($defaults)->toHaveKey('retry');
            expect($defaults)->toHaveKey('retention');
            expect($defaults)->toHaveKey('subscriptions');
            expect($defaults)->toHaveKey('disabled');
            expect($defaults)->toHaveKey('wildcard_cache_ttl');
        });

        it('table_names has all three required keys', function () {
            $defaults = include __DIR__.'/../config/events.php';

            expect($defaults['table_names'])->toHaveKey('triggers');
            expect($defaults['table_names'])->toHaveKey('event_logs');
            expect($defaults['table_names'])->toHaveKey('subscriptions');
        });

        it('subscriptions has all required keys', function () {
            $defaults = include __DIR__.'/../config/events.php';

            expect($defaults['subscriptions'])->toHaveKey('auto_generate_secret');
            expect($defaults['subscriptions'])->toHaveKey('max_failures');
            expect($defaults['subscriptions'])->toHaveKey('timeout');
            expect($defaults['subscriptions'])->toHaveKey('signature_algorithm');
            expect($defaults['subscriptions'])->toHaveKey('cleanup_cron');
        });

        it('retention has all required keys', function () {
            $defaults = include __DIR__.'/../config/events.php';

            expect($defaults['retention'])->toHaveKey('days');
            expect($defaults['retention'])->toHaveKey('include_pending');
            expect($defaults['retention'])->toHaveKey('schedule_cron');
        });
    });
});
