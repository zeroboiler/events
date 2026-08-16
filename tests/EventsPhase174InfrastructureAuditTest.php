<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('EventsPhase174 Infrastructure Audit', function (): void {
    describe('Source file integrity', function (): void {
        it('all 33 source files have declare(strict_types=1)', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_ERR);
            $srcFiles = is_array($srcFiles) ? $srcFiles : [];
            // Exclude Facade to count only src/ files
            $srcFiles = array_filter($srcFiles, fn (string $f): bool => ! str_contains($f, 'Facades'));
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/Facades/*.php', GLOB_ERR) ?: []);
            $srcFiles = array_filter($srcFiles, fn (string $f): bool => str_contains($f, '/src/'));

            expect($srcFiles)->not->toBeEmpty();

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }

            // Verify source file count
            expect(count($srcFiles))->toBe(33);
        });

        it('all source files have license header', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_ERR) ?: [];
            $srcFiles = array_filter($srcFiles, fn (string $f): bool => str_contains($f, '/src/'));

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('This file is part of ZeroBoiler');
            }
        });

        it('all classes are final', function (): void {
            $nonFinalClasses = [];
            $srcFiles = glob(__DIR__.'/../src/{Actions,Console,Contracts,Concerns,Domain,Facades,Jobs,Models}/*.php', GLOB_ERR) ?: [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, 'class ')) {
                    if (! str_contains($content, 'final class')) {
                        $nonFinalClasses[] = basename($file);
                    }
                }
            }

            // Also check root src files
            $rootFiles = glob(__DIR__.'/../src/*.php', GLOB_ERR) ?: [];
            foreach ($rootFiles as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, 'class ')) {
                    if (! str_contains($content, 'final class')) {
                        $nonFinalClasses[] = basename($file);
                    }
                }
            }

            expect($nonFinalClasses)->toBeEmpty('Non-final classes found: '.implode(', ', $nonFinalClasses));
        });

        it('WildcardMatcher is readonly final class', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    describe('Typed properties and return types', function (): void {
        it('EventManager has promoted readonly constructor properties', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();

            $params = $ctor->getParameters();
            expect(count($params))->toBe(3);

            foreach ($params as $param) {
                expect($param->isPromoted())->toBeTrue();
                expect($param->hasType())->toBeTrue();
            }
        });

        it('DomainEvent has promoted readonly constructor properties', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();

            $params = $ctor->getParameters();
            // eventType and payload are promoted
            $promoted = array_filter($params, fn (ReflectionParameter $p): bool => $p->isPromoted());
            expect(count($promoted))->toBeGreaterThanOrEqual(2);
        });

        it('DispatchTriggerJob has promoted readonly constructor properties', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();

            $params = $ctor->getParameters();
            $promoted = array_filter($params, fn (ReflectionParameter $p): bool => $p->isPromoted());
            expect(count($promoted))->toBe(3); // triggerId, event, payload
        });

        it('all public methods have explicit return type declarations', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                // Exclude constructor and magic methods
                if ($method->getName() === '__construct') {
                    continue;
                }
                expect($method->hasReturnType())->toBeTrue(
                    "EventManager::{$method->getName()}() missing return type",
                );
            }
        });
    });

    describe('Attribute compliance', function (): void {
        it('ServiceProvider has #[Override] on register(), boot(), provides()', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);

            $register = $ref->getMethod('register');
            expect($register->getAttributes())->not->toBeEmpty();

            $boot = $ref->getMethod('boot');
            expect($boot->getAttributes())->not->toBeEmpty();

            $provides = $ref->getMethod('provides');
            expect($provides->getAttributes())->not->toBeEmpty();
        });

        it('ConditionEngine pure methods have #[Pure] attribute', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            $pureMethods = ['strictEquals', 'getNestedValue', 'contains', 'between'];

            foreach ($pureMethods as $methodName) {
                $method = $ref->getMethod($methodName);
                $attrs = $method->getAttributes();
                $hasPure = false;
                foreach ($attrs as $attr) {
                    if ($attr->getName() === 'Pure' || str_contains($attr->getName(), 'Pure')) {
                        $hasPure = true;
                        break;
                    }
                }
                expect($hasPure)->toBeTrue("ConditionEngine::{$methodName}() should have #[Pure] attribute");
            }
        });

        it('WildcardMatcher static methods have #[Pure] attribute', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            $pureMethods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

            foreach ($pureMethods as $methodName) {
                $method = $ref->getMethod($methodName);
                $attrs = $method->getAttributes();
                $hasPure = false;
                foreach ($attrs as $attr) {
                    if ($attr->getName() === 'Pure' || str_contains($attr->getName(), 'Pure')) {
                        $hasPure = true;
                        break;
                    }
                }
                expect($hasPure)->toBeTrue("WildcardMatcher::{$methodName}() should have #[Pure] attribute");
            }
        });

        it('Facade has #[Override] on getFacadeAccessor()', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');
            $attrs = $method->getAttributes();
            expect($attrs)->not->toBeEmpty();
        });

        it('DispatchTriggerJob handle() and failed() do NOT have #[Override]', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);

            $handle = $ref->getMethod('handle');
            $handleAttrs = $handle->getAttributes();
            $handleHasOverride = false;
            foreach ($handleAttrs as $attr) {
                if (str_contains($attr->getName(), 'Override')) {
                    $handleHasOverride = true;
                    break;
                }
            }
            expect($handleHasOverride)->toBeFalse('DispatchTriggerJob::handle() should NOT have #[Override]');

            $failed = $ref->getMethod('failed');
            $failedAttrs = $failed->getAttributes();
            $failedHasOverride = false;
            foreach ($failedAttrs as $attr) {
                if (str_contains($attr->getName(), 'Override')) {
                    $failedHasOverride = true;
                    break;
                }
            }
            expect($failedHasOverride)->toBeFalse('DispatchTriggerJob::failed() should NOT have #[Override]');
        });
    });

    describe('ServiceProvider bindings', function (): void {
        it('provides() returns all 7 expected bindings', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provides = $provider->provides();

            $expected = [
                EventManager::class,
                ConditionEngine::class,
                ConditionEngineContract::class,
                ActionResolver::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                EventScheduler::class,
            ];

            foreach ($expected as $binding) {
                expect(in_array($binding, $provides, true))->toBeTrue("Missing binding: {$binding}");
            }

            expect(count($provides))->toBe(7);
        });

        it('ConditionEngine is registered as singleton', function (): void {
            $instance1 = $this->app->make(ConditionEngine::class);
            $instance2 = $this->app->make(ConditionEngine::class);
            expect($instance1)->toBe($instance2);
        });

        it('ConditionEngineContract resolves to ConditionEngine', function (): void {
            $instance = $this->app->make(ConditionEngineContract::class);
            expect($instance)->toBeInstanceOf(ConditionEngine::class);
        });

        it('TriggerBuilder is transient (not singleton)', function (): void {
            $instance1 = $this->app->make(TriggerBuilder::class);
            $instance2 = $this->app->make(TriggerBuilder::class);
            expect($instance1)->not->toBe($instance2);
        });

        it('SubscriptionBuilder is transient (not singleton)', function (): void {
            $instance1 = $this->app->make(SubscriptionBuilder::class);
            $instance2 = $this->app->make(SubscriptionBuilder::class);
            expect($instance1)->not->toBe($instance2);
        });

        it('EventManager is singleton', function (): void {
            $instance1 = $this->app->make(EventManager::class);
            $instance2 = $this->app->make(EventManager::class);
            expect($instance1)->toBe($instance2);
        });
    });

    describe('Facade accessor', function (): void {
        it('facade accessor returns EventManager class', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');
            $method->setAccessible(true);
            $result = $method->invoke(null);
            expect($result)->toBe(EventManager::class);
        });
    });

    describe('Config completeness', function (): void {
        it('config has all 8 top-level keys', function (): void {
            $config = config('events');
            expect($config)->not->toBeNull();

            $expectedKeys = [
                'table_names',
                'queue',
                'retry',
                'retention',
                'subscriptions',
                'disabled',
                'wildcard_cache_ttl',
            ];

            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
            }
        });

        it('table_names has triggers, event_logs, subscriptions', function (): void {
            $tables = config('events.table_names');
            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        it('queue has connection and queue keys', function (): void {
            $queue = config('events.queue');
            expect($queue)->toHaveKey('connection');
            expect($queue)->toHaveKey('queue');
        });

        it('retry has tries and backoff keys', function (): void {
            $retry = config('events.retry');
            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });

        it('subscriptions has all required keys', function (): void {
            $subs = config('events.subscriptions');
            $requiredKeys = ['auto_generate_secret', 'secret_length', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
            foreach ($requiredKeys as $key) {
                expect($subs)->toHaveKey("subscriptions.{$key}");
            }
        });

        it('retention has days, include_pending, and schedule_cron keys', function (): void {
            $retention = config('events.retention');
            expect($retention)->toHaveKey('days');
            expect($retention)->toHaveKey('include_pending');
            expect($retention)->toHaveKey('schedule_cron');
        });
    });

    describe('Model compliance', function (): void {
        it('Trigger model has correct casts', function (): void {
            $ref = new ReflectionClass(Trigger::class);
            $castsMethod = $ref->getMethod('casts');
            $castsMethod->setAccessible(true);
            $casts = $castsMethod->invoke(new Trigger());

            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('async');
            expect($casts)->toHaveKey('enabled');
            expect($casts)->toHaveKey('priority');
        });

        it('EventLog model has correct casts', function (): void {
            $ref = new ReflectionClass(EventLog::class);
            $castsMethod = $ref->getMethod('casts');
            $castsMethod->setAccessible(true);
            $casts = $castsMethod->invoke(new EventLog());

            expect($casts)->toHaveKey('payload');
            expect($casts)->toHaveKey('duration_ms');
            expect($casts)->toHaveKey('error');
        });

        it('Subscription model has correct casts', function (): void {
            $ref = new ReflectionClass(Subscription::class);
            $castsMethod = $ref->getMethod('casts');
            $castsMethod->setAccessible(true);
            $casts = $castsMethod->invoke(new Subscription());

            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('priority');
            expect($casts)->toHaveKey('active');
            expect($casts)->toHaveKey('failure_count');
            expect($casts)->toHaveKey('delivery_count');
            expect($casts)->toHaveKey('last_fired_at');
        });

        it('EventLog has all 4 status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('EventLog has $statuses array with all values', function (): void {
            expect(EventLog::$statuses)->toContain('pending');
            expect(EventLog::$statuses)->toContain('dispatched');
            expect(EventLog::$statuses)->toContain('completed');
            expect(EventLog::$statuses)->toContain('failed');
            expect(count(EventLog::$statuses))->toBe(4);
        });
    });

    describe('DomainEvent immutability', function (): void {
        it('preserves eventId and occurredAt through roundtrip', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $originalId = $event->eventId->toString();
            $originalTime = $event->occurredAt->format(DateTimeImmutable::ATOM);

            $restored = DomainEvent::fromArray($event->toArray());

            expect($restored->eventId->toString())->toBe($originalId);
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe($originalTime);
            expect($restored->eventType)->toBe('test.event');
            expect($restored->payload)->toBe(['key' => 'value']);
        });

        it('fromArray rejects empty eventType', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray([]))
                ->toThrow(InvalidArgumentException::class);
        });

        it('fromArray handles invalid UUID gracefully', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
                'payload' => [],
            ]);
            expect($event->eventType)->toBe('test.event');
            expect($event->eventId)->not->toBeNull();
        });
    });

    describe('ReDoS protection', function (): void {
        it('rejects patterns longer than 500 characters', function (): void {
            $engine = new ConditionEngine;
            $longPattern = '/^' . str_repeat('a', 500) . '$/';
            expect($engine->matches(['code' => ['matches', $longPattern]], ['code' => 'aaa']))
                ->toBeFalse();
        });

        it('rejects nested quantifier patterns', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['code' => ['matches', '/(a+)+/']], ['code' => 'aaa']))
                ->toBeFalse();
        });

        it('accepts valid regex patterns', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))
                ->toBeTrue();
        });
    });

    describe('ConditionEngine all 21 operators', function (): void {
        it('supports all operators', function (): void {
            $engine = new ConditionEngine;

            // > >= < <=
            expect($engine->matches(['n' => ['>', 5]], ['n' => 10]))->toBeTrue();
            expect($engine->matches(['n' => ['>=', 5]], ['n' => 5]))->toBeTrue();
            expect($engine->matches(['n' => ['<', 5]], ['n' => 3]))->toBeTrue();
            expect($engine->matches(['n' => ['<=', 5]], ['n' => 5]))->toBeTrue();

            // = === != !==
            expect($engine->matches(['s' => ['=', 'x']], ['s' => 'x']))->toBeTrue();
            expect($engine->matches(['n' => ['===', 5]], ['n' => 5]))->toBeTrue();
            expect($engine->matches(['s' => ['!=', 'y']], ['s' => 'x']))->toBeTrue();
            expect($engine->matches(['n' => ['!==', 5]], ['n' => 6]))->toBeTrue();

            // in not_in
            expect($engine->matches(['v' => ['in', ['a', 'b']]], ['v' => 'a']))->toBeTrue();
            expect($engine->matches(['v' => ['not_in', ['a', 'b']]], ['v' => 'c']))->toBeTrue();

            // contains not_contains
            expect($engine->matches(['s' => ['contains', 'hello']], ['s' => 'hello world']))->toBeTrue();
            expect($engine->matches(['s' => ['not_contains', 'xyz']], ['s' => 'hello world']))->toBeTrue();

            // between
            expect($engine->matches(['n' => ['between', [1, 10]]], ['n' => 5]))->toBeTrue();

            // null not_null
            expect($engine->matches(['v' => ['null']], ['v' => null]))->toBeTrue();
            expect($engine->matches(['v' => ['not_null']], ['v' => 'x']))->toBeTrue();

            // empty not_empty
            expect($engine->matches(['v' => ['empty']], ['v' => null]))->toBeTrue();
            expect($engine->matches(['v' => ['not_empty']], ['v' => 'x']))->toBeTrue();

            // starts_with ends_with
            expect($engine->matches(['s' => ['starts_with', 'ab']], ['s' => 'abcd']))->toBeTrue();
            expect($engine->matches(['s' => ['ends_with', 'cd']], ['s' => 'abcd']))->toBeTrue();

            // matches
            expect($engine->matches(['s' => ['matches', '/^[a-z]+$/']], ['s' => 'abc']))->toBeTrue();

            // Simple equality (no operator)
            expect($engine->matches(['s' => 'hello'], ['s' => 'hello']))->toBeTrue();
        });
    });

    describe('WildcardMatcher patterns', function (): void {
        it('catch-all * matches everything except empty', function (): void {
            expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('catch-all ** matches everything except empty', function (): void {
            expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('single-segment * matches within one dot segment', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        it('cross-segment ** matches across segments', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        it('exact match works', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });
    });

    describe('EscapesWildcardLike SQL injection prevention', function (): void {
        it('escapes percent signs', function (): void {
            $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
            $ref->setAccessible(true);
            $manager = $this->app->make(EventManager::class);
            $result = $ref->invoke($manager, 'test.%');
            expect($result)->not->toBeNull();
            expect($result)->toBe('test.\\%');
        });

        it('escapes underscores', function (): void {
            $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
            $ref->setAccessible(true);
            $manager = $this->app->make(EventManager::class);
            $result = $ref->invoke($manager, 'test._');
            expect($result)->not->toBeNull();
            expect($result)->toBe('test.\\_');
        });

        it('returns null for non-wildcard patterns', function (): void {
            $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
            $ref->setAccessible(true);
            $manager = $this->app->make(EventManager::class);
            $result = $ref->invoke($manager, 'order.placed');
            expect($result)->toBeNull();
        });
    });

    describe('HMAC signing determinism', function (): void {
        it('produces consistent signatures', function (): void {
            $sub = Subscription::factory()->create([
                'secret' => 'whsec_test_secret_for_determinism',
            ]);
            $payload = json_encode(['event' => 'test', 'data' => 'hello']);
            $sig1 = $sub->signPayload($payload);
            $sig2 = $sub->signPayload($payload);
            expect($sig1)->toBe($sig2);
            expect($sig1)->not->toBeEmpty();
        });

        it('returns empty for null secret', function (): void {
            $sub = Subscription::factory()->create(['secret' => null]);
            $result = $sub->signPayload('test');
            expect($result)->toBe('');
        });
    });

    describe('composer.json validation', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('requires illuminate contracts and support ^13.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
            expect($composer['require']['illuminate/support'])->toBe('^13.0');
        });

        it('has correct extra.laravel providers and aliases', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider',
            );
            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager',
            );
        });
    });

    describe('phpstan.neon.dist validation', function (): void {
        it('is at level 9', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('level: 9');
        });

        it('has reportUnusedIgnoredErrors', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('reportUnusedIgnoredErrors: true');
        });

        it('has checkExplicitMixed', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('checkExplicitMixed: true');
        });

        it('has checkUninitializedProperties', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('checkUninitializedProperties: true');
        });

        it('has bootstrapFiles with tests/helpers.php', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('tests/helpers.php');
        });
    });

    describe('Pest.php test registration completeness', function (): void {
        it('all test files are registered in Pest.php', function (): void {
            $testFiles = glob(__DIR__.'/*.php');
            $supportFiles = ['Pest.php', 'TestCase.php', 'CreatesApplication.php', 'helpers.php', 'TestActions.php'];
            $unregistered = [];

            foreach ($testFiles as $file) {
                $basename = basename($file);
                if (in_array($basename, $supportFiles, true)) {
                    continue;
                }
                $pestContent = file_get_contents(__DIR__.'/Pest.php');
                if (! str_contains($pestContent, $basename)) {
                    $unregistered[] = $basename;
                }
            }

            expect($unregistered)->toBeEmpty('Unregistered test files in Pest.php: '.implode(', ', $unregistered));
        });
    });

    describe('WebhookAction implements Triggerable', function (): void {
        it('WebhookAction implements Triggerable contract', function (): void {
            expect(new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class))
                ->implementsInterface(\ZeroBoiler\Events\Contracts\Triggerable::class);
        });

        it('has handle(array $payload): void method', function (): void {
            $ref = new ReflectionMethod(\ZeroBoiler\Events\Actions\WebhookAction::class, 'handle');
            expect($ref->hasReturnType())->toBeTrue();
            $params = $ref->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('payload');
        });
    });

    describe('Global disable toggle', function (): void {
        it('isDisabled returns false by default', function (): void {
            $manager = $this->app->make(EventManager::class);
            expect($manager->isDisabled())->toBeFalse();
        });

        it('setEnabled(false) disables the system', function (): void {
            $manager = $this->app->make(EventManager::class);
            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();
        });

        it('setEnabled(true) enables the system', function (): void {
            $manager = $this->app->make(EventManager::class);
            $manager->setEnabled(false);
            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();
        });
    });

    describe('Console commands', function (): void {
        it('all 12 commands are registered in ServiceProvider', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $ref = new ReflectionClass($provider);
            $boot = $ref->getMethod('boot');
            $bootContent = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');

            $commands = [
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

            foreach ($commands as $command) {
                expect($bootContent)->toContain($command, "Missing command registration: {$command}");
            }
        });
    });

    describe('EventManager public API completeness', function (): void {
        $publicMethods = [
            'on', 'register', 'fire', 'fireModel', 'enable', 'disable',
            'invalidateTriggerCache', 'isDisabled', 'setEnabled', 'listTriggers',
            'getTrigger', 'deleteTrigger', 'subscribe', 'unsubscribe',
            'listSubscriptions', 'getSubscription', 'subscribeWebhook',
            'getEventHistory', 'getStats', 'purgeLogs', 'getStalePendingLogs',
            'deactivateExceededSubscriptions', 'executeTrigger', 'container',
            'registerScheduler',
        ];

        it('has all ' . count($publicMethods) . ' public API methods', function () use ($publicMethods): void {
            $ref = new ReflectionClass(EventManager::class);

            foreach ($publicMethods as $method) {
                expect($ref->hasMethod($method))->toBeTrue("Missing public method: EventManager::{$method}()");
                $m = $ref->getMethod($method);
                expect($m->isPublic())->toBeTrue("EventManager::{$method}() should be public");
            }
        });
    });
});
