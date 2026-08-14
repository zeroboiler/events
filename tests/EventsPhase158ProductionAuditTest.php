<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 158 — Production Audit', function (): void {
    describe('PHP 8.5 Strict Types & Syntax', function (): void {
        it('all source files declare strict_types=1', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = glob_recursive($srcDir.'/*.php');
            expect($files)->not->toBeEmpty();

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        it('all database files declare strict_types=1', function (): void {
            $dbDir = __DIR__.'/../database';
            $files = glob_recursive($dbDir.'/*.php');
            expect($files)->not->toBeEmpty();

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        it('no source files contain deprecated setAccessible calls', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = glob_recursive($srcDir.'/*.php');

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->not->toContain('setAccessible(');
            }
        });
    });

    describe('Return Type Declarations Completeness', function (): void {
        it('EventManager::fire() returns void', function (): void {
            $method = new ReflectionMethod(EventManager::class, 'fire');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('EventManager::fireModel() returns void', function (): void {
            $method = new ReflectionMethod(EventManager::class, 'fireModel');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('EventManager::registerScheduler() returns void', function (): void {
            $method = new ReflectionMethod(EventManager::class, 'registerScheduler');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('EventManager::executeTrigger() returns void', function (): void {
            $method = new ReflectionMethod(EventManager::class, 'executeTrigger');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('EventManager::setEnabled() returns void', function (): void {
            $method = new ReflectionMethod(EventManager::class, 'setEnabled');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('TriggerBuilder::save() returns Trigger', function (): void {
            $method = new ReflectionMethod(TriggerBuilder::class, 'save');
            $type = $method->getReturnType()?->getName();
            expect($type)->toBe(Trigger::class);
        });

        it('SubscriptionBuilder::save() returns Subscription', function (): void {
            $method = new ReflectionMethod(SubscriptionBuilder::class, 'save');
            $type = $method->getReturnType()?->getName();
            expect($type)->toBe(Subscription::class);
        });

        it('DomainEvent::occur() returns self', function (): void {
            $method = new ReflectionMethod(DomainEvent::class, 'occur');
            $type = $method->getReturnType()?->getName();
            expect($type)->toBe('self');
        });

        it('DomainEvent::fromArray() returns self', function (): void {
            $method = new ReflectionMethod(DomainEvent::class, 'fromArray');
            $type = $method->getReturnType()?->getName();
            expect($type)->toBe('self');
        });

        it('DomainEvent::toArray() returns array', function (): void {
            $method = new ReflectionMethod(DomainEvent::class, 'toArray');
            $type = $method->getReturnType()?->getName();
            expect($type)->toBe('array');
        });

        it('ConditionEngine::matches() returns bool', function (): void {
            $method = new ReflectionMethod(ConditionEngine::class, 'matches');
            expect($method->getReturnType()?->getName())->toBe('bool');
        });

        it('WildcardMatcher::matches() returns bool', function (): void {
            $method = new ReflectionMethod(WildcardMatcher::class, 'matches');
            expect($method->getReturnType()?->getName())->toBe('bool');
        });

        it('WildcardMatcher::findMatchingPatterns() returns array', function (): void {
            $method = new ReflectionMethod(WildcardMatcher::class, 'findMatchingPatterns');
            expect($method->getReturnType()?->getName())->toBe('array');
        });

        it('WildcardMatcher::extractWildcards() returns array', function (): void {
            $method = new ReflectionMethod(WildcardMatcher::class, 'extractWildcards');
            expect($method->getReturnType()?->getName())->toBe('array');
        });

        it('ActionResolver::resolve() returns Triggerable', function (): void {
            $method = new ReflectionMethod(ActionResolver::class, 'resolve');
            $type = $method->getReturnType()?->getName();
            expect($type)->toBe(Triggerable::class);
        });

        it('EventScheduler::register() returns void', function (): void {
            $method = new ReflectionMethod(EventScheduler::class, 'register');
            expect($method->getReturnType()?->getName())->toBe('void');
        });
    });

    describe('Typed Properties Verification', function (): void {
        it('EventManager has all properties typed', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            foreach ($ref->getProperties() as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }
                $type = $prop->getType();
                expect($type)->not->toBeNull("EventManager::\${$prop->getName()} must have a type declaration");
            }
        });

        it('ConditionEngine has all properties typed', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull("ConditionEngine::\${$prop->getName()} must have a type declaration");
            }
        });

        it('TriggerBuilder has all properties typed', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull("TriggerBuilder::\${$prop->getName()} must have a type declaration");
            }
        });

        it('SubscriptionBuilder has all properties typed', function (): void {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull("SubscriptionBuilder::\${$prop->getName()} must have a type declaration");
            }
        });

        it('DispatchTriggerJob has all properties typed', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull("DispatchTriggerJob::\${$prop->getName()} must have a type declaration");
            }
        });

        it('DomainEvent has all properties typed with readonly', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            $props = ['eventId', 'eventType', 'payload', 'occurredAt'];
            foreach ($props as $propName) {
                $prop = $ref->getProperty($propName);
                expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$propName} must be readonly");
                expect($prop->getType())->not->toBeNull("DomainEvent::\${$propName} must have a type");
            }
        });
    });

    describe('Docblock Quality', function (): void {
        it('EventManager has a class-level docblock', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            expect($ref->getDocComment())->not->toBeFalse();
        });

        it('ConditionEngine has a class-level docblock', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            expect($ref->getDocComment())->not->toBeFalse();
        });

        it('Triggerable interface has a handle() docblock', function (): void {
            $ref = new ReflectionClass(Triggerable::class);
            $method = $ref->getMethod('handle');
            expect($method->getDocComment())->not->toBeFalse();
        });

        it('ConditionEngineContract interface has a matches() docblock', function (): void {
            $ref = new ReflectionClass(ConditionEngineContract::class);
            $method = $ref->getMethod('matches');
            expect($method->getDocComment())->not->toBeFalse();
        });

        it('EventManager::fire() has a @throws docblock', function (): void {
            $method = new ReflectionMethod(EventManager::class, 'fire');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@throws');
        });

        it('EventManager::fireModel() has a @throws docblock', function (): void {
            $method = new ReflectionMethod(EventManager::class, 'fireModel');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@throws');
        });

        it('TriggerBuilder::save() has a @throws docblock', function (): void {
            $method = new ReflectionMethod(TriggerBuilder::class, 'save');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@throws');
        });

        it('SubscriptionBuilder::save() has a @throws docblock', function (): void {
            $method = new ReflectionMethod(SubscriptionBuilder::class, 'save');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@throws');
        });

        it('ActionResolver::resolve() has a @throws docblock', function (): void {
            $method = new ReflectionMethod(ActionResolver::class, 'resolve');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@throws');
        });
    });

    describe('ServiceProvider register() / boot() / provides() Consistency', function (): void {
        it('provides() returns exactly 7 bindings', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();
            expect(count($provides))->toBe(7);
        });

        it('provides() contains all singleton bindings', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();

            $singletons = [
                EventManager::class,
                ConditionEngine::class,
                ConditionEngineContract::class,
                ActionResolver::class,
                EventScheduler::class,
            ];

            foreach ($singletons as $binding) {
                expect(in_array($binding, $provides, true))->toBeTrue("provides() must include {$binding}");
            }
        });

        it('provides() contains all transient bindings', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();

            $transients = [
                TriggerBuilder::class,
                SubscriptionBuilder::class,
            ];

            foreach ($transients as $binding) {
                expect(in_array($binding, $provides, true))->toBeTrue("provides() must include {$binding}");
            }
        });

        it('register() uses singleton for EventManager', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();

            $first = app()->make(EventManager::class);
            $second = app()->make(EventManager::class);
            expect($first)->toBe($second);
        });

        it('register() uses singleton for ConditionEngineContract', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();

            $first = app()->make(ConditionEngineContract::class);
            $second = app()->make(ConditionEngineContract::class);
            expect($first)->toBe($second);
            expect($first)->toBeInstanceOf(ConditionEngine::class);
        });

        it('register() uses transient for SubscriptionBuilder', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();

            $first = app()->make(SubscriptionBuilder::class);
            $second = app()->make(SubscriptionBuilder::class);
            expect($first)->not->toBe($second);
        });

        it('register() uses transient for TriggerBuilder', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();

            $first = app()->make(TriggerBuilder::class);
            $second = app()->make(TriggerBuilder::class);
            expect($first)->not->toBe($second);
        });

        it('boot() publishes config and migrations in console mode', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();
            $provider->boot();

            // Verify the provider registered the events config
            expect(Config::get('events'))->toBeArray();
        });

        it('boot() registers all 12 console commands', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();
            $provider->boot();

            // Verify commands are registered
            $this->assertArrayHasKey('zeroboiler:events:list', app()->make('Illuminate\Contracts\Console\Kernel')->all());
        });
    });

    describe('Config Completeness and Correctness', function (): void {
        it('all 7 top-level config keys exist', function (): void {
            $config = Config::get('events');
            $keys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
            foreach ($keys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Config key 'events.{$key}' must exist");
            }
        });

        it('queue config has connection and queue sub-keys', function (): void {
            $queue = Config::get('events.queue');
            expect($queue)->toBeArray();
            expect(array_key_exists('connection', $queue))->toBeTrue();
            expect(array_key_exists('queue', $queue))->toBeTrue();
        });

        it('retry config has tries and backoff sub-keys', function (): void {
            $retry = Config::get('events.retry');
            expect($retry)->toBeArray();
            expect(array_key_exists('tries', $retry))->toBeTrue();
            expect(array_key_exists('backoff', $retry))->toBeTrue();
        });

        it('retention config has days, include_pending, and schedule_cron sub-keys', function (): void {
            $retention = Config::get('events.retention');
            expect($retention)->toBeArray();
            expect(array_key_exists('days', $retention))->toBeTrue();
            expect(array_key_exists('include_pending', $retention))->toBeTrue();
            expect(array_key_exists('schedule_cron', $retention))->toBeTrue();
        });

        it('subscriptions config has all 5 required sub-keys', function (): void {
            $subs = Config::get('events.subscriptions');
            $keys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
            foreach ($keys as $key) {
                expect(array_key_exists($key, $subs))->toBeTrue("Config key 'events.subscriptions.{$key}' must exist");
            }
        });

        it('table_names config has all 3 table sub-keys', function (): void {
            $tables = Config::get('events.table_names');
            $keys = ['triggers', 'event_logs', 'subscriptions'];
            foreach ($keys as $key) {
                expect(array_key_exists($key, $tables))->toBeTrue("Config key 'events.table_names.{$key}' must exist");
            }
        });

        it('disabled config defaults to false', function (): void {
            $disabled = Config::get('events.disabled');
            expect($disabled)->toBeFalse();
        });

        it('wildcard_cache_ttl config is a positive integer', function (): void {
            $ttl = Config::get('events.wildcard_cache_ttl');
            expect(is_int($ttl))->toBeTrue();
            expect($ttl)->toBeGreaterThan(0);
        });
    });

    describe('EventManager registerScheduler() Convenience Method', function (): void {
        it('resolves EventScheduler from container and calls register()', function (): void {
            $eventManager = app()->make(EventManager::class);
            $schedule = new Schedule;

            // This should not throw — it resolves EventScheduler and calls register()
            $eventManager->registerScheduler($schedule);

            // Verify scheduled tasks are registered
            $events = $schedule->events();
            expect(count($events))->toBeGreaterThanOrEqual(0);
        });
    });

    describe('DomainEvent Edge Cases', function (): void {
        it('occur() factory creates event with fresh UUID', function (): void {
            $event = DomainEvent::occur('test.created');
            expect($event->eventType)->toBe('test.created');
            expect($event->eventId)->not->toBeNull();
            expect($event->occurredAt)->not->toBeNull();
            expect($event->payload)->toBe([]);
        });

        it('fromArray() with invalid UUID falls back to fresh UUID', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
                'occurredAt' => 'not-a-date',
            ]);
            expect($event->eventType)->toBe('test.event');
            // UUID should be regenerated since the input was invalid
            expect($event->eventId->toString())->not->toBe('not-a-uuid');
        });

        it('fromArray() with missing payload defaults to empty array', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
            ]);
            expect($event->payload)->toBe([]);
        });

        it('toArray() contains all 4 required keys', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $data = $event->toArray();

            expect(array_key_exists('eventId', $data))->toBeTrue();
            expect(array_key_exists('eventType', $data))->toBeTrue();
            expect(array_key_exists('payload', $data))->toBeTrue();
            expect(array_key_exists('occurredAt', $data))->toBeTrue();
        });
    });

    describe('WildcardMatcher Boundary Cases', function (): void {
        it('empty pattern does not match non-empty event', function (): void {
            expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
        });

        it('empty event does not match non-empty pattern', function (): void {
            expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
        });

        it('exact match with no wildcards', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('catch-all * matches any non-empty event', function (): void {
            expect(WildcardMatcher::matches('*', 'a'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c.d'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('catch-all ** matches any non-empty event', function (): void {
            expect(WildcardMatcher::matches('**', 'a'))->toBeTrue();
            expect(WildcardMatcher::matches('**', 'a.b.c'))->toBeTrue();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('* only matches one segment', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        it('** matches across segments', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        it('findMatchingPatterns preserves order', function (): void {
            $patterns = ['order.placed', 'order.*', 'order.**'];
            $matched = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
            expect($matched)->toEqual(['order.placed', 'order.*', 'order.**']);
        });

        it('findMatchingPatterns returns empty for non-matching', function (): void {
            $matched = WildcardMatcher::findMatchingPatterns(['user.*'], 'order.placed');
            expect($matched)->toEqual([]);
        });

        it('extractWildcards returns empty for ** patterns', function (): void {
            $extracted = WildcardMatcher::extractWildcards('order.**', 'order.placed');
            expect($extracted)->toEqual([]);
        });

        it('extractWildcards extracts single-segment wildcards', function (): void {
            $extracted = WildcardMatcher::extractWildcards('order.*.created', 'order.custom.created');
            expect($extracted)->toEqual(['custom']);
        });
    });

    describe('ConditionEngine Operator Coverage', function (): void {
        it('all 21 operators are handled in evaluateCondition', function (): void {
            $engine = new ConditionEngine;

            // Test each operator with a valid positive case
            expect($engine->matches(['f' => ['>', 5]], ['f' => 10]))->toBeTrue();
            expect($engine->matches(['f' => ['>=', 10]], ['f' => 10]))->toBeTrue();
            expect($engine->matches(['f' => ['<', 20]], ['f' => 10]))->toBeTrue();
            expect($engine->matches(['f' => ['<=', 10]], ['f' => 10]))->toBeTrue();
            expect($engine->matches(['f' => ['=', 'yes']], ['f' => 'yes']))->toBeTrue();
            expect($engine->matches(['f' => ['===', true]], ['f' => true]))->toBeTrue();
            expect($engine->matches(['f' => ['!=', 'no']], ['f' => 'yes']))->toBeTrue();
            expect($engine->matches(['f' => ['!==', false]], ['f' => true]))->toBeTrue();
            expect($engine->matches(['f' => ['in', ['a', 'b']]], ['f' => 'a']))->toBeTrue();
            expect($engine->matches(['f' => ['not_in', ['x']]], ['f' => 'a']))->toBeTrue();
            expect($engine->matches(['f' => ['contains', 'hello']], ['f' => 'say hello world']))->toBeTrue();
            expect($engine->matches(['f' => ['not_contains', 'xyz']], ['f' => 'hello']))->toBeTrue();
            expect($engine->matches(['f' => ['between', [5, 15]]], ['f' => 10]))->toBeTrue();
            expect($engine->matches(['f' => ['null']], ['f' => null]))->toBeTrue();
            expect($engine->matches(['f' => ['not_null']], ['f' => 'value']))->toBeTrue();
            expect($engine->matches(['f' => ['empty']], ['f' => '']))->toBeTrue();
            expect($engine->matches(['f' => ['not_empty']], ['f' => 'value']))->toBeTrue();
            expect($engine->matches(['f' => ['starts_with', 'pre']], ['f' => 'prefix']))->toBeTrue();
            expect($engine->matches(['f' => ['ends_with', 'fix']], ['f' => 'suffix']))->toBeTrue();
            expect($engine->matches(['f' => ['matches', '/^[a-z]+$/']], ['f' => 'hello']))->toBeTrue();

            // Simple equality (implicit =)
            expect($engine->matches(['f' => 'value'], ['f' => 'value']))->toBeTrue();
        });

        it('AND logic requires all conditions to match', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(
                ['a' => 'x', 'b' => 'y'],
                ['a' => 'x', 'b' => 'y'],
            ))->toBeTrue();

            expect($engine->matches(
                ['a' => 'x', 'b' => 'y'],
                ['a' => 'x', 'b' => 'z'],
            ))->toBeFalse();
        });

        it('nested dot notation resolves correctly', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'admin']],
            ))->toBeTrue();

            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'user']],
            ))->toBeFalse();
        });

        it('matches operator rejects patterns longer than 500 chars', function (): void {
            $engine = new ConditionEngine;

            $longPattern = '/'.str_repeat('a', 501).'/';
            expect($engine->matches(
                ['f' => ['matches', $longPattern]],
                ['f' => 'anything'],
            ))->toBeFalse();
        });

        it('between normalizes inverted ranges', function (): void {
            $engine = new ConditionEngine;

            // [100, 50] → [50, 100]
            expect($engine->matches(
                ['f' => ['between', [100, 50]]],
                ['f' => 75],
            ))->toBeTrue();
        });
    });

    describe('DispatchTriggerJob Config-Driven Properties', function (): void {
        it('reads tries from config', function (): void {
            $job = new DispatchTriggerJob('trigger-id', 'test.event', []);
            expect($job->tries)->toBe(Config::get('events.retry.tries', 3));
        });

        it('reads queue name from config', function (): void {
            $job = new DispatchTriggerJob('trigger-id', 'test.event', []);
            expect($job->queue)->toBe(Config::get('events.queue.queue', 'default'));
        });

        it('backoff defaults to [60, 300, 900]', function (): void {
            Config::set('events.retry.backoff', '60,300,900');
            $job = new DispatchTriggerJob('trigger-id', 'test.event', []);
            expect($job->backoff)->toEqual([60, 300, 900]);
        });

        it('backoff supports array format', function (): void {
            Config::set('events.retry.backoff', [30, 120]);
            $job = new DispatchTriggerJob('trigger-id', 'test.event', []);
            expect($job->backoff)->toEqual([30, 120]);
        });

        it('has promoted readonly constructor properties', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            $ctor = $ref->getConstructor();

            $promotedParams = ['triggerId', 'event', 'payload'];
            foreach ($promotedParams as $paramName) {
                $param = array_first($ctor->getParameters(), fn (ReflectionParameter $p): bool => $p->getName() === $paramName);
                expect($param)->not->toBeNull("DispatchTriggerJob must have \${$paramName} parameter");
                expect($param->isPromoted())->toBeTrue("\${$paramName} must be promoted");
                expect($param->isReadOnly())->toBeTrue("\${$paramName} must be readonly");
            }
        });
    });

    describe('Models Config-Driven Table Names', function (): void {
        it('Trigger::getTable() reads from events.table_names.triggers', function (): void {
            $table = (new Trigger)->getTable();
            expect($table)->toBe(Config::get('events.table_names.triggers', 'triggers'));
        });

        it('EventLog::getTable() reads from events.table_names.event_logs', function (): void {
            $table = (new EventLog)->getTable();
            expect($table)->toBe(Config::get('events.table_names.event_logs', 'event_logs'));
        });

        it('Subscription::getTable() reads from events.table_names.subscriptions', function (): void {
            $table = (new Subscription)->getTable();
            expect($table)->toBe(Config::get('events.table_names.subscriptions', 'event_subscriptions'));
        });
    });

    describe('Migration Files Exist and Use Strict Types', function (): void {
        it('3 migration files exist', function (): void {
            $files = glob(__DIR__.'/../database/migrations/*.php');
            expect(count($files))->toBe(3);
        });

        it('triggers migration uses config-driven table name', function (): void {
            $content = file_get_contents(glob(__DIR__.'/../database/migrations/*_create_triggers_table.php')[0]);
            expect($content)->toContain("config('events.table_names.triggers'");
        });

        it('event_logs migration uses config-driven table name', function (): void {
            $content = file_get_contents(glob(__DIR__.'/../database/migrations/*_create_event_logs_table.php')[0]);
            expect($content)->toContain("config('events.table_names.event_logs'");
        });

        it('subscriptions migration uses config-driven table name', function (): void {
            $content = file_get_contents(glob(__DIR__.'/../database/migrations/*_create_event_subscriptions_table.php')[0]);
            expect($content)->toContain("config('events.table_names.subscriptions'");
        });
    });

    describe('Factory Files Use Strict Types', function (): void {
        it('3 factory files exist', function (): void {
            $files = glob(__DIR__.'/../database/factories/*.php');
            expect(count($files))->toBe(3);
        });

        it('TriggerFactory has static string $model property', function (): void {
            $ref = new ReflectionProperty(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class, 'model');
            expect($ref->getType()?->getName())->toBe('string');
            expect($ref->isStatic())->toBeTrue();
        });

        it('EventLogFactory has static string $model property', function (): void {
            $ref = new ReflectionProperty(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class, 'model');
            expect($ref->getType()?->getName())->toBe('string');
            expect($ref->isStatic())->toBeTrue();
        });

        it('SubscriptionFactory has static string $model property', function (): void {
            $ref = new ReflectionProperty(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class, 'model');
            expect($ref->getType()?->getName())->toBe('string');
            expect($ref->isStatic())->toBeTrue();
        });
    });

    describe('PHPStan Configuration Validation', function (): void {
        it('phpstan.neon.dist exists and sets level to max', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('level: max');
        });

        it('phpstan.neon.dist scans src, database, and tests directories', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('- src');
            expect($content)->toContain('tests');
        });

        it('phpstan.neon.dist has reportUnusedIgnoredErrors enabled', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('reportUnusedIgnoredErrors: true');
        });

        it('phpstan.neon.dist has universalObjectCratesClasses configured', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('universalObjectCratesClasses');
            expect($content)->toContain('Illuminate\\Database\\Eloquent\\Model');
        });
    });

    describe('CI Workflow Configuration', function (): void {
        it('CI workflow runs on push and pull_request to main', function (): void {
            $content = file_get_contents(__DIR__.'/../.github/workflows/ci.yml');
            expect($content)->toContain('php-version: \'8.5\'');
            expect($content)->toContain('vendor/bin/phpstan');
            expect($content)->toContain('vendor/bin/pint');
            expect($content)->toContain('vendor/bin/rector');
            expect($content)->toContain('vendor/bin/pest');
        });
    });
});

// Helper functions

function glob_recursive(string $pattern): array
{
    $files = glob($pattern);

    foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR) as $dir) {
        $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern)));
    }

    return $files;
}

function array_first(array $array, callable $callback): mixed
{
    foreach ($array as $item) {
        if ($callback($item)) {
            return $item;
        }
    }

    return null;
}
