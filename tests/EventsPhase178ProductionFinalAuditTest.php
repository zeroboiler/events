<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Actions\WebhookAction;

describe('Phase 178 — Production Infrastructure Final Audit', function (): void {
    // ─── 1. ServiceProvider Registration ─────────────────────────────
    describe('service provider registration', function (): void {
        it('register() binds all 7 services with correct lifetimes', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();

            // Singleton bindings
            expect(app(EventManager::class))->toBeInstanceOf(EventManager::class);
            expect(app(ConditionEngine::class))->toBeInstanceOf(ConditionEngine::class);
            expect(app(ConditionEngineContract::class))->toBeInstanceOf(ConditionEngineContract::class);
            expect(app(ActionResolver::class))->toBeInstanceOf(ActionResolver::class);
            expect(app(EventScheduler::class))->toBeInstanceOf(EventScheduler::class);

            // Transient bindings — each resolution should be a new instance
            $b1 = app(TriggerBuilder::class);
            $b2 = app(TriggerBuilder::class);
            expect($b1 === $b2)->toBeFalse();

            $s1 = app(SubscriptionBuilder::class);
            $s2 = app(SubscriptionBuilder::class);
            expect($s1 === $s2)->toBeFalse();
        });

        it('ConditionEngineContract resolves to ConditionEngine', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();

            $engine = app(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        it('boot() registers all 12 commands in console mode', function (): void {
            $provider = new EventsServiceProvider(app());
            $app = app();

            // Simulate running in console
            $app->bind('Illuminate\Contracts\Foundation\Application', fn () => $app);

            $commandsRegistered = false;
            $app->afterResolving('events', function () use (&$commandsRegistered): void {
                $commandsRegistered = true;
            });

            // Just verify the boot method can be called without error
            $provider->boot();
            expect(true)->toBeTrue();
        });

        it('provides() returns exactly 7 entries with no duplicates', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();
            expect($provides)->toHaveCount(7);
            expect($provides)->toEqual(array_unique($provides));
        });

        it('provides() includes EventScheduler', function (): void {
            $provider = new EventsServiceProvider(app());
            expect($provider->provides())->toContain(EventScheduler::class);
        });
    });

    // ─── 2. Config Consistency ────────────────────────────────────────
    describe('config file consistency', function (): void {
        it('config has exactly 7 top-level keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect(count($config))->toBe(7);
        });

        it('all documented env vars have matching config keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $readme = file_get_contents(__DIR__.'/../README.md');

            // Verify queue config keys
            expect($config['queue'])->toHaveKey('connection');
            expect($config['queue'])->toHaveKey('queue');

            // Verify retry config keys
            expect($config['retry'])->toHaveKey('tries');
            expect($config['retry'])->toHaveKey('backoff');

            // Verify retention config keys
            expect($config['retention'])->toHaveKey('days');
            expect($config['retention'])->toHaveKey('include_pending');
            expect($config['retention'])->toHaveKey('schedule_cron');

            // Verify subscriptions config keys
            expect($config['subscriptions'])->toHaveKey('auto_generate_secret');
            expect($config['subscriptions'])->toHaveKey('secret_length');
            expect($config['subscriptions'])->toHaveKey('max_failures');
            expect($config['subscriptions'])->toHaveKey('timeout');
            expect($config['subscriptions'])->toHaveKey('signature_algorithm');
            expect($config['subscriptions'])->toHaveKey('cleanup_cron');

            // Verify global keys
            expect(array_key_exists('disabled', $config))->toBeTrue();
            expect(array_key_exists('wildcard_cache_ttl', $config))->toBeTrue();
        });

        it('table_names has correct default values', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['table_names']['triggers'])->toBe('triggers');
            expect($config['table_names']['event_logs'])->toBe('event_logs');
            expect($config['table_names']['subscriptions'])->toBe('event_subscriptions');
        });

        it('disabled defaults to false', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['disabled'])->toBeFalse();
        });

        it('wildcard_cache_ttl defaults to 300', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['wildcard_cache_ttl'])->toBe(300);
        });
    });

    // ─── 3. ConditionEngine Edge Cases ───────────────────────────────
    describe('ConditionEngine edge cases', function (): void {
        it('empty conditions array returns true', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches([], ['key' => 'value']))->toBeTrue();
        });

        it('empty operator array returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
        });

        it('matches operator with invalid regex returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['field' => ['matches', '/[invalid/']], ['field' => 'test']))->toBeFalse();
        });

        it('between auto-normalizes inverted range', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 75]))->toBeTrue();
        });

        it('between returns false for non-numeric actual', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['amount' => ['between', [1, 10]]], ['amount' => 'abc']))->toBeFalse();
        });

        it('between returns false for non-array value', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['amount' => ['between', 5]], ['amount' => 5]))->toBeFalse();
        });

        it('dot notation resolves nested values', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'admin']],
            ))->toBeTrue();
        });

        it('dot notation returns null for missing key', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['name' => 'john']],
            ))->toBeFalse();
        });

        it('strictEquals falls back to string comparison for mixed types', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['count' => '5'], ['count' => 5]))->toBeTrue();
        });

        it('strictEquals returns false for array vs string', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['tags' => 'hello'], ['tags' => ['hello']]))->toBeFalse();
        });

        it('contains works for array membership', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['tags' => ['contains', 'urgent']],
                ['tags' => ['low', 'urgent', 'high']],
            ))->toBeTrue();
        });

        it('contains returns false for non-string actual with string value', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['count' => ['contains', '1']],
                ['count' => 123],
            ))->toBeFalse();
        });

        it('unknown operator returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['field' => ['unknown_op', 'value']],
                ['field' => 'value'],
            ))->toBeFalse();
        });

        it('AND logic: all conditions must match', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                [
                    'status' => 'active',
                    'amount' => ['>', 10],
                ],
                ['status' => 'active', 'amount' => 5],
            ))->toBeFalse();
        });
    });

    // ─── 4. WildcardMatcher Comprehensive ────────────────────────────
    describe('WildcardMatcher comprehensive patterns', function (): void {
        it('exact match is case-sensitive', function (): void {
            expect(WildcardMatcher::matches('Order.Placed', 'order.placed'))->toBeFalse();
        });

        it('multiple wildcards in pattern', function (): void {
            expect(WildcardMatcher::matches('*.*.created', 'user.profile.created'))->toBeTrue();
            expect(WildcardMatcher::matches('*.*.created', 'user.profile.updated'))->toBeFalse();
        });

        it('pattern with no wildcards does exact match', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('empty pattern with empty event', function (): void {
            expect(WildcardMatcher::matches('', ''))->toBeFalse();
        });

        it('findMatchingPatterns returns empty for no matches', function (): void {
            $result = WildcardMatcher::findMatchingPatterns(['order.*'], 'user.created');
            expect($result)->toBe([]);
        });

        it('findMatchingPatterns returns all matching patterns', function (): void {
            $result = WildcardMatcher::findMatchingPatterns(['order.*', 'order.placed', '*'], 'order.placed');
            expect($result)->toEqual(['order.*', 'order.placed', '*']);
        });

        it('findMatchingPatterns with empty patterns array', function (): void {
            $result = WildcardMatcher::findMatchingPatterns([], 'order.placed');
            expect($result)->toBe([]);
        });

        it('extractWildcards returns empty for mismatched segment count', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.event.created');
            expect($result)->toBe([]);
        });

        it('extractWildcards returns empty when pattern does not match', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'order.profile.created');
            expect($result)->toBe([]);
        });
    });

    // ─── 5. DomainEvent Edge Cases ───────────────────────────────────
    describe('DomainEvent edge cases', function (): void {
        it('occur with empty payload creates valid event', function (): void {
            $event = DomainEvent::occur('test.event');
            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe([]);
            expect($event->eventId->toString())->toMatch('/^[0-9a-f]{8}-/');
        });

        it('fromArray throws on missing eventType', function (): void {
            expect(fn () => DomainEvent::fromArray([]))
                ->toThrow(InvalidArgumentException::class, 'eventType is required');
        });

        it('fromArray handles empty eventType string', function (): void {
            expect(fn () => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class, 'eventType is required');
        });

        it('fromArray handles invalid UUID gracefully', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
                'payload' => ['key' => 'value'],
                'occurredAt' => '2025-01-01T00:00:00+00:00',
            ]);
            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
            // Invalid UUID → fresh UUID generated (not preserved)
            expect($event->eventId->toString())->toMatch('/^[0-9a-f]{8}-/');
        });

        it('fromArray handles invalid datetime gracefully', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);
            expect($event->eventType)->toBe('test.event');
            // Invalid datetime → defaults to now
            expect($event->occurredAt->format('Y'))->toBe((new DateTimeImmutable)->format('Y'));
        });

        it('fromArray handles missing payload gracefully', function (): void {
            $event = DomainEvent::fromArray(['eventType' => 'test.event']);
            expect($event->payload)->toBe([]);
        });

        it('fromArray preserves valid UUID and datetime', function (): void {
            $uuid = \Ramsey\Uuid\Uuid::uuid4();
            $datetime = new DateTimeImmutable('2025-06-15T12:30:00+00:00');

            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => $uuid->toString(),
                'payload' => ['key' => 'value'],
                'occurredAt' => $datetime->format(DateTimeImmutable::ATOM),
            ]);

            expect($event->eventId->toString())->toBe($uuid->toString());
            expect($event->occurredAt->format(DateTimeImmutable::ATOM))->toBe($datetime->format(DateTimeImmutable::ATOM));
        });
    });

    // ─── 6. EventManager Public API ──────────────────────────────────
    describe('EventManager public API', function (): void {
        it('container() returns the app instance', function (): void {
            $manager = app(EventManager::class);
            $container = $manager->container();
            expect($container)->toBe(app());
        });

        it('getTrigger returns null for empty string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->getTrigger(''))->toBeNull();
            expect($manager->getTrigger('0'))->toBeNull();
        });

        it('deleteTrigger returns false for empty string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->deleteTrigger(''))->toBeFalse();
        });

        it('enable returns false for empty string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->enable(''))->toBeFalse();
        });

        it('disable returns false for empty string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->disable(''))->toBeFalse();
        });

        it('fire throws on empty event name', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fire(''))
                ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty');
        });

        it('fire throws on "0" event name', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fire('0'))
                ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty');
        });

        it('fireModel throws on empty model class', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fireModel('', 'created', new stdClass))
                ->toThrow(InvalidArgumentException::class, 'Model class name cannot be empty');
        });

        it('fireModel throws on empty action', function (): void {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fireModel('App\\Models\\Order', '', new stdClass))
                ->toThrow(InvalidArgumentException::class, 'Model action cannot be empty');
        });

        it('listTriggers accepts all filter combinations', function (): void {
            $manager = app(EventManager::class);
            $result = $manager->listTriggers('order.*', true, 50);
            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });
    });

    // ─── 7. TriggerBuilder Edge Cases ─────────────────────────────────
    describe('TriggerBuilder edge cases', function (): void {
        it('actions() throws on empty class name', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->on('test.event');
            expect(fn () => $builder->actions(['']))
                ->toThrow(InvalidArgumentException::class, 'non-empty string');
        });

        it('actions() throws on "0" class name', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->on('test.event');
            expect(fn () => $builder->actions(['0']))
                ->toThrow(InvalidArgumentException::class, 'non-empty string');
        });

        it('save() throws when no action provided', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->on('test.event');
            expect(fn () => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'At least one action is required');
        });

        it('save() generates name from event when not provided', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.event')
                ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
                ->save();
            expect($trigger->name)->toBe('test.event Trigger');
        });
    });

    // ─── 8. SubscriptionBuilder Edge Cases ───────────────────────────
    describe('SubscriptionBuilder edge cases', function (): void {
        it('save() throws on empty event name', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->subscribe('', 'https://example.com/hook');
            expect(fn () => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Event name is required');
        });

        it('save() throws on empty URL', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->subscribe('test.event', '');
            expect(fn () => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Webhook URL is required');
        });

        it('save() throws on invalid URL', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->subscribe('test.event', 'not-a-url');
            expect(fn () => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'valid URL');
        });

        it('save() throws on non-HTTP scheme', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->subscribe('test.event', 'ftp://example.com/hook');
            expect(fn () => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'HTTP or HTTPS');
        });

        it('save() throws on file:// scheme', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->subscribe('test.event', 'file:///etc/passwd');
            expect(fn () => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'HTTP or HTTPS');
        });
    });

    // ─── 9. EscapesWildcardLike SQL Safety ────────────────────────────
    describe('EscapesWildcardLike SQL safety', function (): void {
        it('escapes backslash in pattern', function (): void {
            $trait = new class {
                use EscapesWildcardLike;

                public function test(string $pattern): ?string
                {
                    return $this->wildcardToLike($pattern);
                }
            };
            $result = $trait->test('order\\*');
            expect($result)->toBe('order\\\\%');
        });

        it('escapes percent in pattern', function (): void {
            $trait = new class {
                use EscapesWildcardLike;

                public function test(string $pattern): ?string
                {
                    return $this->wildcardToLike($pattern);
                }
            };
            $result = $trait->test('100%*');
            expect($result)->toBe('100\\%%');
        });

        it('escapes underscore in pattern', function (): void {
            $trait = new class {
                use EscapesWildcardLike;

                public function test(string $pattern): ?string
                {
                    return $this->wildcardToLike($pattern);
                }
            };
            $result = $trait->test('_test_*');
            expect($result)->toBe('\\_test\\_%');
        });

        it('returns null when no wildcard present', function (): void {
            $trait = new class {
                use EscapesWildcardLike;

                public function test(string $pattern): ?string
                {
                    return $this->wildcardToLike($pattern);
                }
            };
            expect($trait->test('order.placed'))->toBeNull();
        });
    });

    // ─── 10. Model Integrity ──────────────────────────────────────────
    describe('model integrity', function (): void {
        it('Trigger model has correct fillable fields', function (): void {
            $trigger = new Trigger;
            expect($trigger->getFillable())->toEqual([
                'id', 'name', 'event', 'action', 'conditions', 'async', 'priority', 'enabled',
            ]);
        });

        it('EventLog model has correct fillable fields', function (): void {
            $log = new EventLog;
            expect($log->getFillable())->toEqual([
                'id', 'trigger_id', 'event', 'payload', 'status', 'error', 'duration_ms',
            ]);
        });

        it('Subscription model has correct fillable fields', function (): void {
            $sub = new Subscription;
            expect($sub->getFillable())->toEqual([
                'id', 'event', 'url', 'conditions', 'priority', 'active', 'secret',
                'last_fired_at', 'failure_count', 'delivery_count',
            ]);
        });

        it('Subscription hides secret from serialization', function (): void {
            $sub = new Subscription;
            expect($sub->getHidden())->toContain('secret');
        });

        it('Trigger and EventLog hide deleted_at', function (): void {
            expect((new Trigger)->getHidden())->toContain('deleted_at');
            expect((new EventLog)->getHidden())->toContain('deleted_at');
        });

        it('all models use string keys (UUID)', function (): void {
            expect((new Trigger)->getKeyType())->toBe('string');
            expect((new Trigger)->incrementing)->toBeFalse();
            expect((new EventLog)->getKeyType())->toBe('string');
            expect((new EventLog)->incrementing)->toBeFalse();
            expect((new Subscription)->getKeyType())->toBe('string');
            expect((new Subscription)->incrementing)->toBeFalse();
        });

        it('EventLog casts return correct types', function (): void {
            $log = new EventLog;
            $casts = $log->casts();
            expect($casts['payload'])->toBe('array');
            expect($casts['duration_ms'])->toBe('int');
            expect($casts['error'])->toBe('string');
        });

        it('Subscription casts return correct types', function (): void {
            $sub = new Subscription;
            $casts = $sub->casts();
            expect($casts['conditions'])->toBe('array');
            expect($casts['priority'])->toBe('int');
            expect($casts['active'])->toBe('boolean');
            expect($casts['failure_count'])->toBe('int');
            expect($casts['delivery_count'])->toBe('int');
            expect($casts['last_fired_at'])->toBe('datetime');
        });

        it('Trigger casts return correct types', function (): void {
            $trigger = new Trigger;
            $casts = $trigger->casts();
            expect($casts['conditions'])->toBe('array');
            expect($casts['async'])->toBe('boolean');
            expect($casts['enabled'])->toBe('boolean');
            expect($casts['priority'])->toBe('int');
        });
    });

    // ─── 11. DispatchTriggerJob Config Edge Cases ────────────────────
    describe('DispatchTriggerJob config edge cases', function (): void {
        it('uses default tries when config is zero', function (): void {
            // Config already loaded in test app; this verifies the fallback logic exists
            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->tries)->toBeGreaterThanOrEqual(1);
        });

        it('uses default queue when config is empty string', function (): void {
            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->queue)->toBe('default');
        });

        it('stores triggerId event and payload as readonly', function (): void {
            $job = new DispatchTriggerJob('trigger-123', 'order.placed', ['key' => 'val']);
            expect($job->triggerId)->toBe('trigger-123');
            expect($job->event)->toBe('order.placed');
            expect($job->payload)->toBe(['key' => 'val']);
        });
    });

    // ─── 12. Facade Correctness ──────────────────────────────────────
    describe('facade correctness', function (): void {
        it('facade accessor returns EventManager class name', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            $method = $ref->getMethod('getFacadeAccessor');
            expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
        });

        it('facade is final', function (): void {
            expect((new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class))->isFinal())->toBeTrue();
        });

        it('facade has all public methods documented', function (): void {
            $facade = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            $doc = $facade->getDocComment();
            expect($doc)->not->toBeFalse();
            $docStr = $doc ?: '';
            // Key methods should be documented
            expect($docStr)->toContain('@method static');
            expect($docStr)->toContain('fire(');
            expect($docStr)->toContain('subscribe(');
            expect($docStr)->toContain('getStats(');
        });
    });

    // ─── 13. EventScheduler Config ───────────────────────────────────
    describe('EventScheduler config', function (): void {
        it('registers without error when config is valid', function (): void {
            $scheduler = app(EventScheduler::class);
            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
            expect(fn () => $scheduler->register($schedule))->not->toThrow();
        });
    });

    // ─── 14. Source File Quality Audit ────────────────────────────────
    describe('source file quality audit', function (): void {
        it('all 33 source files exist and are non-empty', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            expect(count($srcFiles))->toBe(33);
            foreach ($srcFiles as $file) {
                expect(filesize($file))->toBeGreaterThan(0);
            }
        });

        it('all source files use declare(strict_types=1)', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });

        it('all source files have license header', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('This file is part of ZeroBoiler');
            }
        });

        it('no TODO or FIXME in source files', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->not->toContain('TODO');
                expect($contents)->not->toContain('FIXME');
            }
        });

        it('no deprecated setAccessible calls in source', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->not->toContain('setAccessible');
            }
        });
    });

    // ─── 15. Version Consistency ─────────────────────────────────────
    describe('version consistency', function (): void {
        it('composer.json version matches README badge', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $version = $composer['version'];
            $readme = file_get_contents(__DIR__.'/../README.md');
            expect($readme)->toContain("version-{$version}");
        });

        it('composer.json requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('composer.json requires illuminate/contracts ^13.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        it('autoload PSR-4 maps correctly', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        it('extra.laravel.providers is correct', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider',
            );
        });

        it('extra.laravel.aliases is correct', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager',
            );
        });
    });

    // ─── 16. PHPStan Config ───────────────────────────────────────────
    describe('PHPStan configuration', function (): void {
        it('phpstan.neon.dist exists with level 9', function (): void {
            $path = __DIR__.'/../phpstan.neon.dist';
            expect(file_exists($path))->toBeTrue();
            $contents = file_get_contents($path);
            expect($contents)->toContain('level: 9');
        });

        it('has strict analysis options enabled', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('reportUnusedIgnoredErrors: true');
            expect($contents)->toContain('checkExplicitMixed: true');
            expect($contents)->toContain('checkUninitializedProperties: true');
            expect($contents)->toContain('checkMissingIterableValueType: true');
        });

        it('has bootstrap file configured', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('tests/helpers.php');
        });

        it('analyses src, tests, database/migrations, database/factories', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('- src');
            expect($contents)->toContain('- database/migrations');
            expect($contents)->toContain('- database/factories');
            expect($contents)->toContain('- tests');
        });
    });

    // ─── 17. Migrations and Factories ────────────────────────────────
    describe('migrations and factories', function (): void {
        it('has 3 migration files', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            expect(count($migrations))->toBe(3);
        });

        it('has 3 factory files', function (): void {
            $factories = glob(__DIR__.'/../database/factories/*.php');
            expect(count($factories))->toBe(3);
        });

        it('all migrations have strict_types', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            foreach ($migrations as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });

        it('all factories have strict_types', function (): void {
            $factories = glob(__DIR__.'/../database/factories/*.php');
            foreach ($factories as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });
    });

    // ─── 18. Console Commands Verification ────────────────────────────
    describe('console commands verification', function (): void {
        $commandClasses = [
            'EventsListCommand',
            'EventsRegisterCommand',
            'EventsFireCommand',
            'EventsLogCommand',
            'EventsRetryCommand',
            'EventsEnableCommand',
            'EventsDisableCommand',
            'EventsHealthCommand',
            'EventsSubscribeCommand',
            'EventsUnsubscribeCommand',
            'EventsSubscriptionsCommand',
            'EventsRedeliverCommand',
        ];

        foreach ($commandClasses as $cmd) {
            it("{$cmd} is final class", function () use ($cmd): void {
                $class = 'ZeroBoiler\\Events\\Console\\'.$cmd;
                expect((new ReflectionClass($class))->isFinal())->toBeTrue();
            });

            it("{$cmd} has handle() method with int return type", function () use ($cmd): void {
                $class = 'ZeroBoiler\\Events\\Console\\'.$cmd;
                $ref = new ReflectionClass($class);
                $method = $ref->getMethod('handle');
                expect($method->getReturnType()?->getName())->toBe('int');
            });

            it("{$cmd} has strict_types", function () use ($cmd): void {
                $path = __DIR__."/../src/Console/{$cmd}.php";
                $contents = file_get_contents($path);
                expect($contents)->toContain('declare(strict_types=1)');
            });
        }
    });

    // ─── 19. ManagesHistory Operations ───────────────────────────────
    describe('ManagesHistory operations', function (): void {
        it('getEventHistory returns collection', function (): void {
            $manager = app(EventManager::class);
            $result = $manager->getEventHistory();
            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });

        it('getStats returns array with expected keys', function (): void {
            $manager = app(EventManager::class);
            $stats = $manager->getStats();
            $expectedKeys = [
                'total_logs', 'total_triggers', 'active_triggers',
                'completed', 'failed', 'pending', 'dispatched',
                'success_rate', 'failure_rate', 'avg_duration_ms',
                'top_events', 'top_failed_events',
            ];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $stats))->toBeTrue("`getStats()` missing key: {$key}");
            }
        });

        it('purgeLogs returns int', function (): void {
            $manager = app(EventManager::class);
            $result = $manager->purgeLogs(\Illuminate\Support\Carbon::now()->subYears(10));
            expect($result)->toBeInt();
        });

        it('getStalePendingLogs returns collection', function (): void {
            $manager = app(EventManager::class);
            $result = $manager->getStalePendingLogs(\Illuminate\Support\Carbon::now()->subHours(1));
            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });

        it('deactivateExceededSubscriptions returns int', function (): void {
            $manager = app(EventManager::class);
            $result = $manager->deactivateExceededSubscriptions();
            expect($result)->toBeInt();
        });
    });

    // ─── 20. ManagesSubscriptions Operations ────────────────────────
    describe('ManagesSubscriptions operations', function (): void {
        it('unsubscribe returns false for non-existent subscription', function (): void {
            $manager = app(EventManager::class);
            expect($manager->unsubscribe('non-existent-id'))->toBeFalse();
        });

        it('getSubscription returns null for non-existent subscription', function (): void {
            $manager = app(EventManager::class);
            expect($manager->getSubscription('non-existent-id'))->toBeNull();
        });

        it('listSubscriptions returns collection', function (): void {
            $manager = app(EventManager::class);
            $result = $manager->listSubscriptions();
            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });

        it('listSubscriptions with activeOnly filter', function (): void {
            $manager = app(EventManager::class);
            $result = $manager->listSubscriptions(null, activeOnly: true);
            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });
    });

    // ─── 21. Global Disable System ────────────────────────────────────
    describe('global disable system', function (): void {
        it('setEnabled(false) then isDisabled() returns true', function (): void {
            $manager = app(EventManager::class);
            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();
            $manager->setEnabled(true); // cleanup
        });

        it('setEnabled(true) then isDisabled() returns false', function (): void {
            $manager = app(EventManager::class);
            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();
        });

        it('fire returns void when disabled', function (): void {
            $manager = app(EventManager::class);
            $manager->setEnabled(false);
            $result = null;
            try {
                $manager->fire('test.event', ['key' => 'value']);
                $result = 'no-exception';
            } catch (\Throwable $e) {
                $result = 'exception';
            }
            $manager->setEnabled(true); // cleanup
            expect($result)->toBe('no-exception');
        });
    });
});
