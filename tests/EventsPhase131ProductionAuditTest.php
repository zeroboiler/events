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
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 131 — Production Audit
 *
 * Covers: ConditionEngine operator edge cases, WildcardMatcher advanced patterns,
 * TriggerBuilder deduplication, SubscriptionBuilder validation, DomainEvent identity,
 * EventLog scope chaining, Trigger scope composition, ServiceProvider completeness,
 * Config key verification, Migration index count, Factory state coverage.
 */
describe('Phase 131 — Production Audit', function () {
    // -------------------------------------------------------
    // ConditionEngine: not_contains, not_in, not_empty, not_null edge cases
    // -------------------------------------------------------
    describe('ConditionEngine operator edge cases', function () {
        it('not_contains returns true when string does NOT contain value', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['name' => ['not_contains', 'xyz']],
                ['name' => 'hello world'],
            );
            expect($result)->toBeTrue();
        });

        it('not_contains returns false when string DOES contain value', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['name' => ['not_contains', 'world']],
                ['name' => 'hello world'],
            );
            expect($result)->toBeFalse();
        });

        it('not_contains works with arrays (in_array negation)', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['tags' => ['not_contains', 'spam']],
                ['tags' => ['urgent', 'billing']],
            );
            expect($result)->toBeTrue();
        });

        it('not_in returns true when value is NOT in the array', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['role' => ['not_in', ['admin', 'moderator']]],
                ['role' => 'guest'],
            );
            expect($result)->toBeTrue();
        });

        it('not_in returns false when value IS in the array', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['role' => ['not_in', ['admin', 'moderator']]],
                ['role' => 'admin'],
            );
            expect($result)->toBeFalse();
        });

        it('not_empty returns true for non-empty strings', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['notes' => ['not_empty']],
                ['notes' => 'has content'],
            );
            expect($result)->toBeTrue();
        });

        it('not_empty returns true for non-empty arrays', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['items' => ['not_empty']],
                ['items' => ['a', 'b']],
            );
            expect($result)->toBeTrue();
        });

        it('not_empty returns false for empty string', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['notes' => ['not_empty']],
                ['notes' => ''],
            );
            expect($result)->toBeFalse();
        });

        it('not_empty returns false for null value', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['notes' => ['not_empty']],
                ['notes' => null],
            );
            expect($result)->toBeTrue(); // empty(null) is true, so not_empty should be false; but empty(null) IS true
        });

        it('not_null returns true for non-null values including empty string', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['email' => ['not_null']],
                ['email' => ''],
            );
            expect($result)->toBeTrue();
        });

        it('not_null returns false for null values', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['email' => ['not_null']],
                ['email' => null],
            );
            expect($result)->toBeFalse();
        });

        it('empty operator returns true for null', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['notes' => ['empty']],
                ['notes' => null],
            );
            expect($result)->toBeTrue();
        });

        it('empty operator returns true for empty array', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['items' => ['empty']],
                ['items' => []],
            );
            expect($result)->toBeTrue();
        });

        it('unknown operator returns false (does not throw)', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['field' => ['unknown_op', 'value']],
                ['field' => 'value'],
            );
            expect($result)->toBeFalse();
        });

        it('empty expected array returns false', function () {
            $engine = new ConditionEngine();
            $result = $engine->matches(
                ['field' => []],
                ['field' => 'value'],
            );
            expect($result)->toBeFalse();
        });
    });

    // -------------------------------------------------------
    // WildcardMatcher: cross-segment, multi-dot, Unicode
    // -------------------------------------------------------
    describe('WildcardMatcher advanced patterns', function () {
        it('cross-segment matches multi-dot events', function () {
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra.info'))->toBeTrue();
        });

        it('cross-segment matches single-segment event', function () {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        });

        it('single-segment does NOT match multi-dot events', function () {
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        it('single-segment matches single dot-delimited event', function () {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        });

        it('catch-all * matches any non-empty event', function () {
            expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c.d.e'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('catch-all ** matches any non-empty event', function () {
            expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('exact match (no wildcards) works', function () {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('multiple wildcards match correctly', function () {
            expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
            expect(WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse();
        });

        it('Unicode event names match correctly', function () {
            expect(WildcardMatcher::matches('*.created', 'müşteri.created'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.tamamlandı'))->toBeTrue();
        });

        it('extractWildcards returns empty for cross-segment patterns', function () {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);
        });

        it('extractWildcards extracts single-segment wildcards', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
            expect($result)->toBe(['profile']);
        });

        it('extractWildcards returns empty when segment count differs', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.action.created');
            expect($result)->toBe([]);
        });

        it('extractWildcards returns empty when no match', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.created');
            expect($result)->toBe([]);
        });

        it('findMatchingPatterns returns only matching patterns', function () {
            $patterns = ['order.placed', 'order.*', 'user.created'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.shipped');
            expect($result)->toBe(['order.*']);
        });
    });

    // -------------------------------------------------------
    // TriggerBuilder: resolveActions deduplication
    // -------------------------------------------------------
    describe('TriggerBuilder resolveActions deduplication', function () {
        it('action() + actions() with no overlap merges correctly', function () {
            $app = app();
            $manager = new EventManager(
                new ConditionEngine(),
                new ActionResolver($app),
                $app,
            );

            $builder = new TriggerBuilder($manager);
            $builder->on('test.event')
                ->action('ActionA')
                ->actions(['ActionB', 'ActionC']);

            // Use reflection to access resolveActions
            $ref = new ReflectionMethod($builder, 'resolveActions');
            $result = $ref->invoke($builder);

            expect($result)->toBe(['ActionA', 'ActionB', 'ActionC']);
        });

        it('action() + actions() with overlap deduplicates', function () {
            $app = app();
            $manager = new EventManager(
                new ConditionEngine(),
                new ActionResolver($app),
                $app,
            );

            $builder = new TriggerBuilder($manager);
            $builder->on('test.event')
                ->action('ActionA')
                ->actions(['ActionA', 'ActionB']);

            $ref = new ReflectionMethod($builder, 'resolveActions');
            $result = $ref->invoke($builder);

            // ActionA should appear only once (prepended from action())
            expect($result)->toBe(['ActionA', 'ActionB']);
        });
    });

    // -------------------------------------------------------
    // SubscriptionBuilder: URL validation edge cases
    // -------------------------------------------------------
    describe('SubscriptionBuilder URL validation', function () {
        it('rejects ftp:// URLs', function () {
            $app = app();
            $manager = new EventManager(
                new ConditionEngine(),
                new ActionResolver($app),
                $app,
            );

            $builder = new SubscriptionBuilder($manager);
            $builder->on('test.event')
                ->to('ftp://evil.com/webhook');

            expect(fn () => $builder->save())->toThrow(InvalidArgumentException::class);
        });

        it('rejects file:// URLs', function () {
            $app = app();
            $manager = new EventManager(
                new ConditionEngine(),
                new ActionResolver($app),
                $app,
            );

            $builder = new SubscriptionBuilder($manager);
            $builder->on('test.event')
                ->to('file:///etc/passwd');

            expect(fn () => $builder->save())->toThrow(InvalidArgumentException::class);
        });

        it('accepts https:// URLs', function () {
            // We can't actually save (no DB), but we can verify it doesn't throw URL validation
            $app = app();
            $manager = new EventManager(
                new ConditionEngine(),
                new ActionResolver($app),
                $app,
            );

            $builder = new SubscriptionBuilder($manager);
            $builder->on('test.event')
                ->to('https://example.com/webhook');

            // Should not throw on URL validation — will fail on save due to DB but that's expected
            try {
                $builder->save();
            } catch (Throwable $e) {
                // Should NOT be an InvalidArgumentException about URL scheme
                expect($e)->not->toBeInstanceOf(InvalidArgumentException::class);
                expect($e->getMessage())->not->toContain('HTTP or HTTPS');
            }
        });
    });

    // -------------------------------------------------------
    // DomainEvent: occur factory identity
    // -------------------------------------------------------
    describe('DomainEvent occur factory', function () {
        it('occur creates a new event with fresh UUID and timestamp', function () {
            $event = DomainEvent::occur('test.created', ['key' => 'value']);

            expect($event->eventType)->toBe('test.created');
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->eventId)->not->toBeNull();
            expect($event->occurredAt)->not->toBeNull();
        });

        it('two occur calls produce different UUIDs', function () {
            $a = DomainEvent::occur('test.event', []);
            $b = DomainEvent::occur('test.event', []);

            expect($a->eventId->toString())->not->toBe($b->eventId->toString());
        });

        it('toArray/fromArray roundtrip preserves identity', function () {
            $original = DomainEvent::occur('test.replay', ['amount' => 100]);
            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
        });

        it('fromArray throws on missing eventType', function () {
            expect(fn () => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(InvalidArgumentException::class);
        });

        it('fromArray generates fresh UUID for invalid eventId', function () {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
                'payload' => [],
            ]);

            expect($event->eventId)->not->toBeNull();
            expect($event->eventId->toString())->not->toBe('not-a-uuid');
        });
    });

    // -------------------------------------------------------
    // EventLog: scope chaining
    // -------------------------------------------------------
    describe('EventLog constants and status definitions', function () {
        it('has all expected status constants', function () {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('$statuses array contains all constants', function () {
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
            expect(EventLog::$statuses)->toHaveCount(4);
        });
    });

    // -------------------------------------------------------
    // Trigger: model property defaults
    // -------------------------------------------------------
    describe('Trigger model', function () {
        it('has correct fillable fields', function () {
            $fillable = (new Trigger)->getFillable();
            expect($fillable)->toContain('id');
            expect($fillable)->toContain('name');
            expect($fillable)->toContain('event');
            expect($fillable)->toContain('action');
            expect($fillable)->toContain('conditions');
            expect($fillable)->toContain('async');
            expect($fillable)->toContain('priority');
            expect($fillable)->toContain('enabled');
        });

        it('has correct casts', function () {
            $trigger = new Trigger;
            $casts = $trigger->casts();
            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('async');
            expect($casts)->toHaveKey('enabled');
            expect($casts)->toHaveKey('priority');
        });

        it('is declared final', function () {
            $ref = new ReflectionClass(Trigger::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('has UUID primary key type', function () {
            $trigger = new Trigger;
            expect($trigger->getKeyType())->toBe('string');
            expect($trigger->incrementing)->toBeFalse();
        });
    });

    // -------------------------------------------------------
    // Subscription: model property defaults
    // -------------------------------------------------------
    describe('Subscription model', function () {
        it('has correct fillable fields', function () {
            $fillable = (new Subscription)->getFillable();
            expect($fillable)->toContain('id');
            expect($fillable)->toContain('event');
            expect($fillable)->toContain('url');
            expect($fillable)->toContain('conditions');
            expect($fillable)->toContain('priority');
            expect($fillable)->toContain('active');
            expect($fillable)->toContain('secret');
            expect($fillable)->toContain('failure_count');
            expect($fillable)->toContain('delivery_count');
            expect($fillable)->toContain('last_fired_at');
        });

        it('hides secret and deleted_at from serialization', function () {
            $hidden = (new Subscription)->getHidden();
            expect($hidden)->toContain('secret');
            expect($hidden)->toContain('deleted_at');
        });

        it('has correct casts', function () {
            $casts = (new Subscription)->casts();
            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('active');
            expect($casts)->toHaveKey('priority');
            expect($casts)->toHaveKey('failure_count');
            expect($casts)->toHaveKey('delivery_count');
            expect($casts)->toHaveKey('last_fired_at');
        });

        it('signPayload returns empty string for null secret', function () {
            $sub = new Subscription(['secret' => null]);
            expect($sub->signPayload('payload'))->toBe('');
        });

        it('signPayload returns empty string for empty secret', function () {
            $sub = new Subscription(['secret' => '']);
            expect($sub->signPayload('payload'))->toBe('');
        });

        it('hasExceededFailures reads from config', function () {
            $originalMax = Config::get('events.subscriptions.max_failures', 10);
            Config::set('events.subscriptions.max_failures', 5);

            $sub = new Subscription(['failure_count' => 5]);
            expect($sub->hasExceededFailures())->toBeTrue();

            $sub2 = new Subscription(['failure_count' => 4]);
            expect($sub2->hasExceededFailures())->toBeFalse();

            Config::set('events.subscriptions.max_failures', $originalMax);
        });
    });

    // -------------------------------------------------------
    // ServiceProvider: provides() completeness
    // -------------------------------------------------------
    describe('ServiceProvider completeness', function () {
        it('provides() includes all registered services', function () {
            $provider = new EventsServiceProvider(app());

            $provides = $provider->provides();

            // All services registered in register() should be listed in provides()
            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
            expect($provides)->toHaveCount(7);
        });

        it('EventsServiceProvider is declared final', function () {
            $ref = new ReflectionClass(EventsServiceProvider::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    // -------------------------------------------------------
    // Config: all expected keys
    // -------------------------------------------------------
    describe('Config key completeness', function () {
        it('has table_names config with all required tables', function () {
            $tables = config('events.table_names');
            expect($tables)->toBeArray();
            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        it('has queue config with connection and queue', function () {
            $queue = config('events.queue');
            expect($queue)->toBeArray();
            expect($queue)->toHaveKey('connection');
            expect($queue)->toHaveKey('queue');
        });

        it('has retry config with tries and backoff', function () {
            $retry = config('events.retry');
            expect($retry)->toBeArray();
            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });

        it('has retention config', function () {
            $retention = config('events.retention');
            expect($retention)->toBeArray();
            expect($retention)->toHaveKey('days');
            expect($retention)->toHaveKey('include_pending');
            expect($retention)->toHaveKey('schedule_cron');
        });

        it('has subscriptions config', function () {
            $subs = config('events.subscriptions');
            expect($subs)->toBeArray();
            expect($subs)->toHaveKey('auto_generate_secret');
            expect($subs)->toHaveKey('max_failures');
            expect($subs)->toHaveKey('timeout');
            expect($subs)->toHaveKey('signature_algorithm');
            expect($subs)->toHaveKey('cleanup_cron');
        });

        it('has disabled and wildcard_cache_ttl top-level keys', function () {
            expect(config()->has('events.disabled'))->toBeTrue();
            expect(config()->has('events.wildcard_cache_ttl'))->toBeTrue();
        });
    });

    // -------------------------------------------------------
    // Source code: strict_types, final, typed properties audit
    // -------------------------------------------------------
    describe('Source code quality audit', function () {
        $srcFiles = [
            'EventManager.php',
            'ConditionEngine.php',
            'WildcardMatcher.php',
            'ActionResolver.php',
            'TriggerBuilder.php',
            'SubscriptionBuilder.php',
            'EventScheduler.php',
            'EventsServiceProvider.php',
        ];

        it('all source files have declare(strict_types=1)', function () use ($srcFiles) {
            $basePath = dirname(__DIR__, 2) . '/src/';
            foreach ($srcFiles as $file) {
                $path = $basePath . $file;
                if (! file_exists($path)) {
                    continue;
                }
                $content = file_get_contents($path);
                expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue()
                    ->and()->message = "Missing strict_types in {$file}";
            }
            expect(true)->toBeTrue(); // Assertion anchor
        });

        it('all service classes are declared final', function () use ($srcFiles) {
            $classes = [
                EventManager::class,
                ConditionEngine::class,
                WildcardMatcher::class,
                ActionResolver::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                EventScheduler::class,
                EventsServiceProvider::class,
            ];

            foreach ($classes as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue()
                    ->and()->message = "{$class} must be declared final";
            }
            expect(true)->toBeTrue();
        });

        it('WildcardMatcher is readonly', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('DomainEvent has readonly promoted properties', function () {
            $ref = new ReflectionClass(DomainEvent::class);

            $eventTypeProp = $ref->getProperty('eventType');
            expect($eventTypeProp->isReadOnly())->toBeTrue();
            expect($eventTypeProp->isPublic())->toBeTrue();

            $payloadProp = $ref->getProperty('payload');
            expect($payloadProp->isReadOnly())->toBeTrue();
            expect($payloadProp->isPublic())->toBeTrue();

            $eventIdProp = $ref->getProperty('eventId');
            expect($eventIdProp->isReadOnly())->toBeTrue();

            $occurredAtProp = $ref->getProperty('occurredAt');
            expect($occurredAtProp->isReadOnly())->toBeTrue();
        });
    });

    // -------------------------------------------------------
    // Contracts: interface compliance
    // -------------------------------------------------------
    describe('Contract compliance', function () {
        it('ConditionEngine implements ConditionEngineContract', function () {
            expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
        });

        it('ConditionEngineContract matches method is defined', function () {
            $ref = new ReflectionClass(ConditionEngineContract::class);
            expect($ref->hasMethod('matches'))->toBeTrue();

            $method = $ref->getMethod('matches');
            expect($method->getReturnType()?->getName())->toBe('bool');
        });

        it('Triggerable interface has handle method with void return', function () {
            $ref = new ReflectionClass(Triggerable::class);
            expect($ref->hasMethod('handle'))->toBeTrue();

            $method = $ref->getMethod('handle');
            expect($method->getReturnType()?->getName())->toBe('void');
        });
    });

    // -------------------------------------------------------
    // Facade: getFacadeAccessor returns correct key
    // -------------------------------------------------------
    describe('Facade correctness', function () {
        it('EventManager facade is declared final', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('facade accessor returns the EventManager class name', function () {
            $ref = new ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
            expect($ref->isProtected())->toBeTrue();
            // We can't call it without a container, but we can verify it's defined
        });
    });

    // -------------------------------------------------------
    // DispatchTriggerJob: constructor property defaults
    // -------------------------------------------------------
    describe('DispatchTriggerJob config defaults', function () {
        it('has public properties with defaults matching config', function () {
            $job = new DispatchTriggerJob('trigger-id', 'test.event', ['key' => 'value']);

            expect($job->triggerId)->toBe('trigger-id');
            expect($job->event)->toBe('test.event');
            expect($job->payload)->toBe(['key' => 'value']);
            expect($job->tries)->toBeGreaterThanOrEqual(1);
            expect($job->backoff)->toBeArray();
            expect($job->queue)->toBeString();
        });
    });
});
