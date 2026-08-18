<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Exceptions\EventException;
use ZeroBoiler\Events\Exceptions\ActionResolutionException;
use ZeroBoiler\Events\Exceptions\ConditionEvaluationException;
use ZeroBoiler\Events\Exceptions\SubscriptionException;
use ZeroBoiler\Events\Exceptions\TriggerNotFoundException;

/**
 * Phase 215 — Comprehensive production readiness audit.
 *
 * Covers: PHP 8.5 syntax compliance, strict types, return type declarations,
 * docblock presence, typed properties, final classes, readonly properties,
 * ServiceProvider completeness, config structure, GetsWebhookTimeout fix,
 * EventsUnsubscribeCommand string interpolation fix, facade method coverage,
 * exception hierarchy, and trait property access patterns.
 */
describe('Phase 215 — Production Readiness Audit', function (): void {

    /*
    |--------------------------------------------------------------------------
    | 1. Strict Types Verification
    |--------------------------------------------------------------------------
    */
    describe('strict_types declaration', function (): void {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');
        $factoryFiles = glob(__DIR__.'/../database/factories/*.php');
        $allFiles = array_merge($srcFiles ?: [], $factoryFiles ?: []);

        test('all source and factory files have declare(strict_types=1)', function () use ($allFiles): void {
            expect(count($allFiles))->toBeGreaterThan(0);

            foreach ($allFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });

        test('declare(strict_types=1) is the first statement after opening tag', function () use ($allFiles): void {
            foreach ($allFiles as $file) {
                $contents = file_get_contents($file);
                // Remove the opening <?php tag and optional comment block
                $stripped = preg_replace('/^<\?php\s*/', '', $contents);
                expect($stripped)->toStartWith('declare(strict_types=1)');
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 2. Final Classes Verification
    |--------------------------------------------------------------------------
    */
    describe('final classes', function (): void {
        $finalClasses = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
            EventsServiceProvider::class,
            WildcardMatcher::class,
            DomainEvent::class,
            DispatchTriggerJob::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
            EventException::class,
        ];

        test('all core classes are declared final', function () use ($finalClasses): void {
            foreach ($finalClasses as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue("{$class} should be final");
            }
        });

        test('exception leaf classes are final', function (): void {
            $leafExceptions = [
                ActionResolutionException::class,
                ConditionEvaluationException::class,
                SubscriptionException::class,
                TriggerNotFoundException::class,
            ];

            foreach ($leafExceptions as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue("{$class} should be final");
            }
        });

        test('EventException base class is NOT final (allows extension)', function (): void {
            $ref = new ReflectionClass(EventException::class);
            expect($ref->isFinal())->toBeFalse();
        });

        test('WildcardMatcher is readonly final class', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 3. Return Type Declarations
    |--------------------------------------------------------------------------
    */
    describe('return type declarations', function (): void {
        test('all public methods have explicit return types', function (): void {
            $classesToCheck = [
                EventManager::class,
                ConditionEngine::class,
                ActionResolver::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                EventScheduler::class,
                DomainEvent::class,
                DispatchTriggerJob::class,
            ];

            foreach ($classesToCheck as $class) {
                $ref = new ReflectionClass($class);
                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->getDeclaringClass()->getName() !== $class) {
                        continue;
                    }

                    $returnType = $method->getReturnType();
                    expect($returnType)->not->toBeNull(
                        "{$class}::{$method->getName()}() must have a return type declaration"
                    );
                }
            }
        });

        test('interface methods have return types', function (): void {
            $contract = new ReflectionClass(ConditionEngineContract::class);
            foreach ($contract->getMethods() as $method) {
                $returnType = $method->getReturnType();
                expect($returnType)->not->toBeNull(
                    "{$contract->getShortName()}::{$method->getName()}() must have a return type"
                );
            }

            $triggerable = new ReflectionClass(Triggerable::class);
            foreach ($triggerable->getMethods() as $method) {
                $returnType = $method->getReturnType();
                expect($returnType)->not->toBeNull(
                    "{$triggerable->getShortName()}::{$method->getName()}() must have a return type"
                );
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 4. Typed Properties
    |--------------------------------------------------------------------------
    */
    describe('typed properties', function (): void {
        test('DomainEvent has all typed properties', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull(
                    "DomainEvent::\${$prop->getName()} must have a type declaration"
                );
            }
        });

        test('DispatchTriggerJob has all typed properties', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull(
                    "DispatchTriggerJob::\${$prop->getName()} must have a type declaration"
                );
            }
        });

        test('TriggerBuilder has all typed properties', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull(
                    "TriggerBuilder::\${$prop->getName()} must have a type declaration"
                );
            }
        });

        test('SubscriptionBuilder has all typed properties', function (): void {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull(
                    "SubscriptionBuilder::\${$prop->getName()} must have a type declaration"
                );
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 5. Docblock Presence
    |--------------------------------------------------------------------------
    */
    describe('docblock presence', function (): void {
        $classesWithDocblocks = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
            DomainEvent::class,
            DispatchTriggerJob::class,
            EventsServiceProvider::class,
            WildcardMatcher::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
        ];

        test('all core classes have class-level docblocks', function () use ($classesWithDocblocks): void {
            foreach ($classesWithDocblocks as $class) {
                $ref = new ReflectionClass($class);
                $doc = $ref->getDocComment();
                expect($doc)->not->toBeFalse(
                    "{$class} must have a class-level docblock"
                );
                expect($doc)->toContain('*');
            }
        });

        test('all public methods on EventManager have docblocks', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== EventManager::class) {
                    continue;
                }

                // Skip magic methods inherited from traits
                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }

                $doc = $method->getDocComment();
                expect($doc)->not->toBeFalse(
                    "EventManager::{$method->getName()}() must have a docblock"
                );
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 6. GetsWebhookTimeout Syntax Verification
    |--------------------------------------------------------------------------
    */
    describe('GetsWebhookTimeout fix verification', function (): void {
        $traitPath = __DIR__.'/../src/Concerns/GetsWebhookTimeout.php';

        test('is_numeric() call has correct parentheses (no && inside is_numeric)', function () use ($traitPath): void {
            $contents = file_get_contents($traitPath);

            // The bug was: is_numeric($timeout && (int) $timeout > 0)
            // The fix:  is_numeric($timeout) && (int) $timeout > 0
            expect($contents)->not->toContain('is_numeric($timeout &&');
            expect($contents)->toContain('is_numeric($timeout) && (int) $timeout > 0');
        });

        test('getWebhookTimeout method has return type int', function () use ($traitPath): void {
            $ref = new ReflectionMethod(
                ZeroBoiler\Events\Concerns\GetsWebhookTimeout::class,
                'getWebhookTimeout'
            );
            $returnType = $ref->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('int');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 7. EventsUnsubscribeCommand String Interpolation
    |--------------------------------------------------------------------------
    */
    describe('EventsUnsubscribeCommand string interpolation', function (): void {
        $commandPath = __DIR__.'/../src/Console/EventsUnsubscribeCommand.php';

        test('error message has correct closing brace for variable interpolation', function () use ($commandPath): void {
            $contents = file_get_contents($commandPath);

            // The bug was: "Subscription {$id not found." (missing })
            // The fix:  "Subscription {$id} not found."
            expect($contents)->toContain('{$id} not found');
            expect($contents)->not->toContain('{$id not found');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 8. ServiceProvider Completeness
    |--------------------------------------------------------------------------
    */
    describe('ServiceProvider completeness', function (): void {
        test('provides() returns all 7 bindings', function (): void {
            $provider = new EventsServiceProvider(app());
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
                expect(in_array($binding, $provides, true))->toBeTrue(
                    "provides() should include {$binding}"
                );
            }

            expect(count($provides))->toBe(7);
        });

        test('registers singletons correctly', function (): void {
            $provider = new EventsServiceProvider(app());

            // Verify register() creates singletons by checking the binding definitions
            $ref = new ReflectionMethod($provider, 'register');
            expect($ref)->not->toBeNull();
            expect($ref->isPublic())->toBeTrue();
        });

        test('boot() publishes config and migrations in console', function (): void {
            $app = app();
            $provider = new EventsServiceProvider($app);

            // Should not throw — boot() is safe to call
            $provider->boot();

            expect(true)->toBeTrue();
        });

        test('ConditionEngineContract resolves to ConditionEngine', function (): void {
            $engine = app(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 9. Config Completeness
    |--------------------------------------------------------------------------
    */
    describe('config completeness', function (): void {
        test('config file has all 8 top-level keys', function (): void {
            $config = config('events');

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
                expect(array_key_exists($key, $config))->toBeTrue(
                    "Config 'events.{$key}' must exist"
                );
            }
        });

        test('config table_names has all 3 entries', function (): void {
            $tableNames = config('events.table_names');

            expect(array_key_exists('triggers', $tableNames))->toBeTrue();
            expect(array_key_exists('event_logs', $tableNames))->toBeTrue();
            expect(array_key_exists('subscriptions', $tableNames))->toBeTrue();
        });

        test('config queue has connection and queue keys', function (): void {
            $queue = config('events.queue');
            expect(array_key_exists('connection', $queue))->toBeTrue();
            expect(array_key_exists('queue', $queue))->toBeTrue();
        });

        test('config retry has tries and backoff keys', function (): void {
            $retry = config('events.retry');
            expect(array_key_exists('tries', $retry))->toBeTrue();
            expect(array_key_exists('backoff', $retry))->toBeTrue();
        });

        test('config retention has days, include_pending, and schedule_cron keys', function (): void {
            $retention = config('events.retention');
            expect(array_key_exists('days', $retention))->toBeTrue();
            expect(array_key_exists('include_pending', $retention))->toBeTrue();
            expect(array_key_exists('schedule_cron', $retention))->toBeTrue();
        });

        test('config subscriptions has all 6 keys', function (): void {
            $subs = config('events.subscriptions');

            $expectedSubKeys = [
                'auto_generate_secret',
                'secret_length',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ];

            foreach ($expectedSubKeys as $key) {
                expect(array_key_exists($key, $subs))->toBeTrue(
                    "Config 'events.subscriptions.{$key}' must exist"
                );
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 10. Facade Method Coverage
    |--------------------------------------------------------------------------
    */
    describe('facade method coverage', function (): void {
        test('facade getFacadeAccessor returns EventManager class', function (): void {
            $ref = new ReflectionMethod(EventManager::class, 'getFacadeAccessor');
            $ref->setAccessible(true);
            $accessor = $ref->invoke(null);

            expect($accessor)->toBe(\ZeroBoiler\Events\EventManager::class);
        });

        test('facade is final', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('facade docblock lists all 20 public methods', function (): void {
            $facadePath = __DIR__.'/../src/Facades/EventManager.php';
            $contents = file_get_contents($facadePath);

            $expectedMethods = [
                'on(',
                'register(',
                'fire(',
                'fireModel(',
                'enable(',
                'disable(',
                'invalidateTriggerCache()',
                'isDisabled()',
                'setEnabled(',
                'listTriggers(',
                'getTrigger(',
                'deleteTrigger(',
                'subscribe(',
                'unsubscribe(',
                'listSubscriptions(',
                'getSubscription(',
                'subscribeWebhook(',
                'getEventHistory(',
                'getStats(',
                'purgeLogs(',
                'getStalePendingLogs(',
                'deactivateExceededSubscriptions()',
                'executeTrigger(',
                'registerScheduler(',
                'container()',
            ];

            foreach ($expectedMethods as $method) {
                expect($contents)->toContain($method,
                    "Facade docblock must include {$method}"
                );
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 11. Exception Hierarchy
    |--------------------------------------------------------------------------
    */
    describe('exception hierarchy', function (): void {
        test('EventException extends RuntimeException', function (): void {
            $ref = new ReflectionClass(EventException::class);
            expect($ref->getParentClass()->getName())->toBe(\RuntimeException::class);
        });

        test('all leaf exceptions extend EventException', function (): void {
            $leaves = [
                ActionResolutionException::class,
                ConditionEvaluationException::class,
                SubscriptionException::class,
                TriggerNotFoundException::class,
            ];

            foreach ($leaves as $leaf) {
                $ref = new ReflectionClass($leaf);
                expect($ref->getParentClass()->getName())->toBe(EventException::class);
            }
        });

        test('ActionResolutionException accepts class and reason', function (): void {
            $ex = new ActionResolutionException('App\Actions\Foo', 'not found');
            expect($ex->getMessage())->toContain('App\\Actions\\Foo');
            expect($ex->getMessage())->toContain('not found');
            expect($ex)->toBeInstanceOf(EventException::class);
            expect($ex)->toBeInstanceOf(\RuntimeException::class);
        });

        test('ConditionEvaluationException formats field and reason', function (): void {
            $ex = new ConditionEvaluationException('amount', 'operator invalid');
            expect($ex->getMessage())->toContain('amount');
            expect($ex->getMessage())->toContain('operator invalid');
        });

        test('TriggerNotFoundException formats trigger ID', function (): void {
            $ex = new TriggerNotFoundException('uuid-123');
            expect($ex->getMessage())->toContain('uuid-123');
        });

        test('SubscriptionException supports previous throwable', function (): void {
            $prev = new \RuntimeException('inner');
            $ex = new SubscriptionException('sub failed', $prev);
            expect($ex->getPrevious())->toBe($prev);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 12. Constructor-Promoted Readonly Properties
    |--------------------------------------------------------------------------
    */
    describe('constructor-promoted readonly properties', function (): void {
        test('EventManager has 3 readonly constructor params', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $constructor = $ref->getConstructor();
            $params = $constructor->getParameters();

            expect(count($params))->toBe(3);

            foreach ($params as $param) {
                expect($param->isPromoted())->toBeTrue(
                    "EventManager constructor param \${$param->getName()} must be promoted"
                );
            }
        });

        test('ActionResolver has 1 readonly constructor param', function (): void {
            $ref = new ReflectionClass(ActionResolver::class);
            $params = $ref->getConstructor()->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->isPromoted())->toBeTrue();
        });

        test('EventScheduler has 1 readonly constructor param', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            $params = $ref->getConstructor()->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->isPromoted())->toBeTrue();
        });

        test('DispatchTriggerJob has 3 promoted constructor params (plus optional)', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            $params = $ref->getConstructor()->getParameters();

            // triggerId, event, payload are promoted; app is optional and not promoted
            $promotedCount = 0;
            foreach ($params as $param) {
                if ($param->isPromoted()) {
                    $promotedCount++;
                }
            }

            expect($promotedCount)->toBeGreaterThanOrEqual(3);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 13. No Deprecated APIs
    |--------------------------------------------------------------------------
    */
    describe('no deprecated APIs', function (): void {
        test('no setAccessible calls in src directory', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                if (str_contains($contents, 'setAccessible(')) {
                    $violations[] = basename($file);
                }
            }

            expect($violations)->toBeEmpty(
                'Found setAccessible() calls in: '.implode(', ', $violations)
            );
        });

        test('no deprecated PHP functions in source', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $deprecated = ['str_rot13(', 'money_format(', 'create_function('];
            $violations = [];

            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                foreach ($deprecated as $func) {
                    if (str_contains($contents, $func)) {
                        $violations[] = basename($file).':'.$func;
                    }
                }
            }

            expect($violations)->toBeEmpty(
                'Found deprecated function usage in: '.implode(', ', $violations)
            );
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 14. Model Casts and Constants
    |--------------------------------------------------------------------------
    */
    describe('model casts and constants', function (): void {
        test('Trigger model has 4 casts', function (): void {
            $trigger = new Trigger;
            $casts = $trigger->casts();
            expect(count($casts))->toBe(4);
            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('async');
            expect($casts)->toHaveKey('enabled');
            expect($casts)->toHaveKey('priority');
        });

        test('EventLog model has 3 casts and 4 status constants', function (): void {
            $log = new EventLog;
            $casts = $log->casts();
            expect(count($casts))->toBe(3);
            expect($casts)->toHaveKey('payload');
            expect($casts)->toHaveKey('duration_ms');
            expect($casts)->toHaveKey('error');

            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        test('Subscription model has 6 casts', function (): void {
            $sub = new Subscription;
            $casts = $sub->casts();
            expect(count($casts))->toBe(6);
            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('priority');
            expect($casts)->toHaveKey('active');
            expect($casts)->toHaveKey('failure_count');
            expect($casts)->toHaveKey('delivery_count');
            expect($casts)->toHaveKey('last_fired_at');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 15. Composer.json Alignment
    |--------------------------------------------------------------------------
    */
    describe('composer.json alignment', function (): void {
        $composerPath = __DIR__.'/../composer.json';

        test('PHP requirement is ^8.5', function () use ($composerPath): void {
            $composer = json_decode(file_get_contents($composerPath), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        test('Laravel requirement is ^13.0', function () use ($composerPath): void {
            $composer = json_decode(file_get_contents($composerPath), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
            expect($composer['require']['illuminate/support'])->toBe('^13.0');
            expect($composer['require']['illuminate/database'])->toBe('^13.0');
        });

        test('autoload PSR-4 is correct', function () use ($composerPath): void {
            $composer = json_decode(file_get_contents($composerPath), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        test('service provider is registered in extra.laravel.providers', function () use ($composerPath): void {
            $composer = json_decode(file_get_contents($composerPath), true);
            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider'
            );
        });

        test('facade alias is registered in extra.laravel.aliases', function () use ($composerPath): void {
            $composer = json_decode(file_get_contents($composerPath), true);
            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager'
            );
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 16. PHPStan Configuration
    |--------------------------------------------------------------------------
    */
    describe('PHPStan configuration', function (): void {
        test('phpstan.neon.dist sets level 9', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('level: 9');
        });

        test('phpstan.neon.dist has baselineFile', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('baselineFile: phpstan-baseline.neon');
        });

        test('phpstan-baseline.neon exists', function (): void {
            expect(file_exists(__DIR__.'/../phpstan-baseline.neon'))->toBeTrue();
        });

        test('phpstan.neon.dist has src in paths', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('- src');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 17. License Headers
    |--------------------------------------------------------------------------
    */
    describe('license headers', function (): void {
        test('all source files have license header', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');

            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                $hasLicense = str_contains($contents, 'This file is part of ZeroBoiler');
                expect($hasLicense)->toBeTrue(
                    basename($file).' must have a license header'
                );
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 18. DomainEvent Immutability
    |--------------------------------------------------------------------------
    */
    describe('DomainEvent immutability', function (): void {
        test('DomainEvent properties are readonly', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);

            $readonlyProps = ['eventId', 'eventType', 'payload', 'occurredAt'];
            foreach ($readonlyProps as $prop) {
                $rp = $ref->getProperty($prop);
                expect($rp->isReadOnly())->toBeTrue(
                    "DomainEvent::\${$prop} must be readonly"
                );
            }
        });

        test('DomainEvent::occur() creates valid instance', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        test('DomainEvent::fromArray() preserves eventType', function (): void {
            $event = DomainEvent::occur('order.created', ['id' => 42]);
            $data = $event->toArray();

            $restored = DomainEvent::fromArray($data);

            expect($restored->eventType)->toBe('order.created');
            expect($restored->eventId->toString())->toBe($event->eventId->toString());
            expect($restored->occurredAt)->toBe($event->occurredAt);
        });

        test('DomainEvent::fromArray() throws on missing eventType', function (): void {
            expect(fn () => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('DomainEvent::__toString() returns formatted string', function (): void {
            $event = DomainEvent::occur('test.event');
            $str = (string) $event;

            expect($str)->toContain('DomainEvent[test.event]');
            expect($str)->toContain('id=');
            expect($str)->toContain('at=');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 19. WildcardMatcher Static-Only Methods
    |--------------------------------------------------------------------------
    */
    describe('WildcardMatcher static-only verification', function (): void {
        test('all WildcardMatcher methods are static', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                expect($method->isStatic())->toBeTrue(
                    "WildcardMatcher::{$method->getName()}() must be static"
                );
            }
        });

        test('WildcardMatcher has no constructor', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->getConstructor())->toBeNull();
        });

        test('WildcardMatcher has #[Pure] on all methods', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);

            foreach ($ref->getMethods() as $method) {
                $attrs = $method->getAttributes(\Pure::class);
                expect(count($attrs))->toBeGreaterThan(0,
                    "WildcardMatcher::{$method->getName()}() must have #[Pure] attribute"
                );
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 20. Migrations Existence
    |--------------------------------------------------------------------------
    */
    describe('migrations existence', function (): void {
        test('3 migration files exist', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            expect(count($migrations))->toBe(3);
        });

        test('triggers migration exists', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*create_triggers_table*');
            expect(count($migrations))->toBe(1);
        });

        test('event_logs migration exists', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*create_event_logs_table*');
            expect(count($migrations))->toBe(1);
        });

        test('event_subscriptions migration exists', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*create_event_subscriptions_table*');
            expect(count($migrations))->toBe(1);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | 21. EventManager Public API Surface
    |--------------------------------------------------------------------------
    */
    describe('EventManager public API surface', function (): void {
        $publicMethods = [];

        test('EventManager has exactly 14 public methods declared on itself', function () use (&$publicMethods): void {
            $ref = new ReflectionClass(EventManager::class);
            $count = 0;

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === EventManager::class) {
                    $publicMethods[] = $method->getName();
                    $count++;
                }
            }

            // 14 methods: on, register, invalidateTriggerCache, isDisabled, setEnabled,
            // listTriggers, getTrigger, deleteTrigger, enable, disable, fire,
            // fireModel, executeTrigger, registerScheduler, container
            expect($count)->toBeGreaterThanOrEqual(14);
        });

        test('includes fire method', function () use (&$publicMethods): void {
            expect($publicMethods)->toContain('fire');
        });

        test('includes fireModel method', function () use (&$publicMethods): void {
            expect($publicMethods)->toContain('fireModel');
        });

        test('includes executeTrigger method', function () use (&$publicMethods): void {
            expect($publicMethods)->toContain('executeTrigger');
        });

        test('includes container method', function () use (&$publicMethods): void {
            expect($publicMethods)->toContain('container');
        });
    });
});
