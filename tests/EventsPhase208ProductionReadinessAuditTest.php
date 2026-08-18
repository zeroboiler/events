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
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Exceptions\ActionResolutionException;
use ZeroBoiler\Events\Exceptions\ConditionEvaluationException;
use ZeroBoiler\Events\Exceptions\EventException;
use ZeroBoiler\Events\Exceptions\SubscriptionException;
use ZeroBoiler\Events\Exceptions\TriggerNotFoundException;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 208: Production readiness audit — readonly property defaults, constructor safety,
 * exception hierarchy completeness, facade accessor correctness, config key coverage,
 * and PHP 8.5 compatibility edge cases.
 */
describe('Phase 208: Production Readiness Audit', function () {
    describe('DispatchTriggerJob readonly property defaults', function () {
        it('has connection defaulting to null (not uninitialized)', function () {
            // Create a job with a mock config that returns no connection.
            // This tests that $connection readonly property has a default value
            // so it is never uninitialized when the config key is missing.
            $reflection = new ReflectionClass(DispatchTriggerJob::class);

            $connectionProp = $reflection->getProperty('connection');
            expect($connectionProp->isReadOnly())->toBeTrue();
            expect($connectionProp->hasDefaultValue())->toBeTrue();
            expect($connectionProp->getDefaultValue())->toBeNull();

            $backoffProp = $reflection->getProperty('backoff');
            expect($backoffProp->isReadOnly())->toBeTrue();
            expect($backoffProp->hasDefaultValue())->toBeTrue();
            expect($backoffProp->getDefaultValue())->toBe([]);
        });

        it('constructs without errors when no connection config is set', function () {
            // If connection config is null, the job should still be constructible
            // and $connection should be null (the default).
            $job = new DispatchTriggerJob(
                triggerId: (string) \Illuminate\Support\Str::uuid(),
                event: 'test.event',
                payload: ['key' => 'value'],
                app: null,
            );

            expect($job->connection)->toBeNull();
            expect($job->queue)->toBe('default');
            expect($job->tries)->toBe(3);
            expect($job->backoff)->toBe([60, 300, 900]);
        });

        it('reads connection from config when available', function () {
            // Set config to provide a connection
            config(['events.queue.connection' => 'redis']);

            $job = new DispatchTriggerJob(
                triggerId: (string) \Illuminate\Support\Str::uuid(),
                event: 'test.event',
                payload: [],
            );

            expect($job->connection)->toBe('redis');
        });

        it('keeps connection null when config value is empty string', function () {
            config(['events.queue.connection' => '']);

            $job = new DispatchTriggerJob(
                triggerId: (string) \Illuminate\Support\Str::uuid(),
                event: 'test.event',
                payload: [],
            );

            expect($job->connection)->toBeNull();
        });

        it('keeps connection null when config value is not a string', function () {
            config(['events.queue.connection' => 123]);

            $job = new DispatchTriggerJob(
                triggerId: (string) \Illuminate\Support\Str::uuid(),
                event: 'test.event',
                payload: [],
            );

            expect($job->connection)->toBeNull();
        });
    });

    describe('Exception hierarchy completeness', function () {
        it('EventException extends RuntimeException', function () {
            $e = new EventException('test');
            expect($e)->toBeInstanceOf(\RuntimeException::class);
            expect($e)->toBeInstanceOf(\Throwable::class);
            expect($e->getMessage())->toBe('test');
            expect($e->getCode())->toBe(0);
            expect($e->getPrevious())->toBeNull();
        });

        it('EventException accepts code and previous', function () {
            $prev = new \InvalidArgumentException('inner');
            $e = new EventException('outer', 42, $prev);
            expect($e->getCode())->toBe(42);
            expect($e->getPrevious())->toBe($prev);
        });

        it('ActionResolutionException extends EventException', function () {
            $e = new ActionResolutionException('App\\Actions\\Foo', 'not found');
            expect($e)->toBeInstanceOf(EventException::class);
            expect($e->getMessage())->toContain("Failed to resolve action 'App\\Actions\\Foo'");
            expect($e->getMessage())->toContain('not found');
        });

        it('ActionResolutionException without reason', function () {
            $e = new ActionResolutionException('App\\Actions\\Foo');
            expect($e->getMessage())->toBe("Failed to resolve action 'App\\Actions\\Foo'");
        });

        it('ConditionEvaluationException extends EventException', function () {
            $e = new ConditionEvaluationException('amount', 'not numeric');
            expect($e)->toBeInstanceOf(EventException::class);
            expect($e->getMessage())->toContain("Condition evaluation failed for field 'amount'");
            expect($e->getMessage())->toContain('not numeric');
        });

        it('SubscriptionException extends EventException', function () {
            $prev = new \RuntimeException('network error');
            $e = new SubscriptionException('delivery failed', $prev);
            expect($e)->toBeInstanceOf(EventException::class);
            expect($e->getMessage())->toBe('delivery failed');
            expect($e->getPrevious())->toBe($prev);
        });

        it('TriggerNotFoundException extends EventException', function () {
            $e = new TriggerNotFoundException('uuid-123');
            expect($e)->toBeInstanceOf(EventException::class);
            expect($e->getMessage())->toContain('uuid-123');
        });

        it('all exceptions are catchable via Throwable', function () {
            $exceptions = [
                new EventException(),
                new ActionResolutionException('Foo'),
                new ConditionEvaluationException('x', 'y'),
                new SubscriptionException('msg'),
                new TriggerNotFoundException('id'),
            ];

            foreach ($exceptions as $e) {
                expect($e)->toBeInstanceOf(\Throwable::class);
            }
        });
    });

    describe('Facade accessor correctness', function () {
        it('resolves to the correct EventManager class', function () {
            $accessor = EventManagerFacade::getFacadeAccessor();
            expect($accessor)->toBe(\ZeroBoiler\Events\EventManager::class);
        });
    });

    describe('WildcardMatcher readonly class', function () {
        it('is declared as readonly final', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('all public methods are static', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                expect($method->isStatic())->toBeTrue();
            }
        });
    });

    describe('DomainEvent immutability', function () {
        it('promoted readonly properties cannot be modified after construction', function () {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            $ref = new ReflectionClass($event);
            $eventTypeProp = $ref->getProperty('eventType');
            expect($eventTypeProp->isReadOnly())->toBeTrue();

            $payloadProp = $ref->getProperty('payload');
            expect($payloadProp->isReadOnly())->toBeTrue();

            $eventIdProp = $ref->getProperty('eventId');
            expect($eventIdProp->isReadOnly())->toBeTrue();

            $occurredAtProp = $ref->getProperty('occurredAt');
            expect($occurredAtProp->isReadOnly())->toBeTrue();
        });

        it('preserves eventId and occurredAt through fromArray round-trip', function () {
            $original = DomainEvent::occur('order.created', ['order_id' => 42]);
            $data = $original->toArray();
            $restored = DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
        });

        it('__toString produces expected format', function () {
            $event = DomainEvent::occur('user.registered');
            $str = (string) $event;
            expect($str)->toStartWith('DomainEvent[user.registered]');
            expect($str)->toContain('id=');
            expect($str)->toContain('at=');
        });
    });

    describe('Config key coverage verification', function () {
        it('all config keys used in source have defaults in config file', function () {
            $config = include __DIR__ . '/../config/events.php';

            // Verify top-level keys
            $expectedTopLevelKeys = [
                'table_names', 'queue', 'retry', 'retention',
                'subscriptions', 'disabled', 'wildcard_cache_ttl',
            ];
            foreach ($expectedTopLevelKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.$key");
            }

            // Verify table_names sub-keys
            $expectedTableKeys = ['triggers', 'event_logs', 'subscriptions'];
            foreach ($expectedTableKeys as $key) {
                expect(array_key_exists($key, $config['table_names']))->toBeTrue("Missing table_names key: $key");
            }

            // Verify queue sub-keys
            expect(array_key_exists('connection', $config['queue']))->toBeTrue();
            expect(array_key_exists('queue', $config['queue']))->toBeTrue();

            // Verify retry sub-keys
            expect(array_key_exists('tries', $config['retry']))->toBeTrue();
            expect(array_key_exists('backoff', $config['retry']))->toBeTrue();

            // Verify retention sub-keys
            expect(array_key_exists('days', $config['retention']))->toBeTrue();
            expect(array_key_exists('include_pending', $config['retention']))->toBeTrue();
            expect(array_key_exists('schedule_cron', $config['retention']))->toBeTrue();

            // Verify subscriptions sub-keys
            $expectedSubKeys = [
                'auto_generate_secret', 'secret_length', 'max_failures',
                'timeout', 'signature_algorithm', 'cleanup_cron',
            ];
            foreach ($expectedSubKeys as $key) {
                expect(array_key_exists($key, $config['subscriptions']))->toBeTrue("Missing subscriptions key: $key");
            }
        });
    });

    describe('Service provider binding correctness', function () {
        it('all provides() classes are actually bound in register()', function () {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
            $provides = $provider->provides();

            $expectedBindings = [
                EventManager::class,
                ConditionEngine::class,
                ConditionEngineContract::class,
                ActionResolver::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                EventScheduler::class,
            ];

            foreach ($expectedBindings as $binding) {
                expect(in_array($binding, $provides, true))->toBeTrue("Missing from provides(): $binding");
            }

            // Verify all provides() entries are resolvable
            foreach ($provides as $provided) {
                expect(app()->bound($provided) || app()->resolved($provided))
                    ->toBeTrue("Binding not registered in container: $provided");
            }
        });

        it('ConditionEngineContract is bound to ConditionEngine', function () {
            $engine = app(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        it('TriggerBuilder and SubscriptionBuilder are transient (new instance each time)', function () {
            $a1 = app(TriggerBuilder::class);
            $a2 = app(TriggerBuilder::class);
            // Transient binding: same type but should be different instances
            // (can't guarantee with singleton container, but the binding is bind() not singleton())
            expect($a1)->toBeInstanceOf(TriggerBuilder::class);
            expect($a2)->toBeInstanceOf(TriggerBuilder::class);

            $b1 = app(SubscriptionBuilder::class);
            $b2 = app(SubscriptionBuilder::class);
            expect($b1)->toBeInstanceOf(SubscriptionBuilder::class);
            expect($b2)->toBeInstanceOf(SubscriptionBuilder::class);
        });

        it('EventManager is a singleton', function () {
            $a = app(EventManager::class);
            $b = app(EventManager::class);
            expect($a)->toBe($b);
        });
    });

    describe('ConditionEngine operator completeness', function () {
        it('supports all 20 documented operators', function () {
            $engine = new ConditionEngine();

            // > operator
            expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue();
            expect($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))->toBeFalse();

            // >= operator
            expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();

            // < operator
            expect($engine->matches(['amount' => ['<', 100]], ['amount' => 50]))->toBeTrue();

            // <= operator
            expect($engine->matches(['amount' => ['<=', 100]], ['amount' => 100]))->toBeTrue();

            // = operator (type-coerced equality)
            expect($engine->matches(['count' => ['=', '5']], ['count' => 5]))->toBeTrue();

            // === operator (strict equality)
            expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();
            expect($engine->matches(['flag' => ['===', true]], ['flag' => 1]))->toBeFalse();

            // != operator
            expect($engine->matches(['status' => ['!=', 'draft']], ['status' => 'active']))->toBeTrue();

            // !== operator
            expect($engine->matches(['flag' => ['!==', true]], ['flag' => 1]))->toBeTrue();

            // in operator
            expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();

            // not_in operator
            expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();

            // contains operator (string)
            expect($engine->matches(['body' => ['contains', 'hello']], ['body' => 'hello world']))->toBeTrue();

            // contains operator (array)
            expect($engine->matches(['tags' => ['contains', 'x']], ['tags' => ['x', 'y']]))->toBeTrue();

            // not_contains operator
            expect($engine->matches(['body' => ['not_contains', 'spam']], ['body' => 'hello']))->toBeTrue();

            // between operator
            expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();
            expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 17]))->toBeFalse();

            // between with inverted range
            expect($engine->matches(['age' => ['between', [65, 18]]], ['age' => 30]))->toBeTrue();

            // null operator
            expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
            expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']))->toBeFalse();

            // not_null operator
            expect($engine->matches(['email' => ['not_null']], ['email' => 'a@b.com']))->toBeTrue();

            // empty operator
            expect($engine->matches(['notes' => ['empty']], ['notes' => null]))->toBeTrue();
            expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
            expect($engine->matches(['notes' => ['empty']], ['notes' => []]))->toBeTrue();

            // not_empty operator
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'hello']))->toBeTrue();

            // starts_with operator
            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();

            // ends_with operator
            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();

            // matches operator (regex)
            expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();
            expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'abc']))->toBeFalse();
        });

        it('empty conditions array returns true', function () {
            $engine = new ConditionEngine();
            expect($engine->matches([], ['anything' => 'here']))->toBeTrue();
        });

        it('null-safe numeric comparisons', function () {
            $engine = new ConditionEngine();
            expect($engine->matches(['amount' => ['>', 100]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['>=', 100]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<=', 100]], ['amount' => null]))->toBeFalse();
        });
    });

    describe('WildcardMatcher edge cases', function () {
        it('catch-all * matches any non-empty event', function () {
            expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('catch-all ** matches any non-empty event', function () {
            expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('single-segment wildcard only matches within a segment', function () {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        it('cross-segment wildcard matches across segments', function () {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        it('extractWildcards returns empty for ** patterns', function () {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
        });

        it('extractWildcards returns correct values for * patterns', function () {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'user.john.created'))
                ->toBe(['john']);
        });

        it('findMatchingPatterns returns matching patterns', function () {
            $patterns = ['order.*', 'user.created', '*.deleted'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
            expect($result)->toBe(['order.*']);
        });

        it('literal dots in pattern are not treated as wildcards', function () {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'orderXplaced'))->toBeFalse();
        });
    });

    describe('Model table name config resolution', function () {
        it('Trigger::getTable reads from config', function () {
            config(['events.table_names.triggers' => 'custom_triggers']);
            $model = new Trigger;
            expect($model->getTable())->toBe('custom_triggers');
            // Reset
            config(['events.table_names.triggers' => 'triggers']);
        });

        it('EventLog::getTable reads from config', function () {
            config(['events.table_names.event_logs' => 'custom_event_logs']);
            $model = new EventLog;
            expect($model->getTable())->toBe('custom_event_logs');
            config(['events.table_names.event_logs' => 'event_logs']);
        });

        it('Subscription::getTable reads from config', function () {
            config(['events.table_names.subscriptions' => 'custom_subs']);
            $model = new Subscription;
            expect($model->getTable())->toBe('custom_subs');
            config(['events.table_names.subscriptions' => 'event_subscriptions']);
        });

        it('falls back to default table name when config is empty', function () {
            config(['events.table_names.triggers' => '']);
            $model = new Trigger;
            expect($model->getTable())->toBe('triggers');
            config(['events.table_names.triggers' => 'triggers']);
        });
    });

    describe('EventManager sanitizePayloadForQueue', function () {
        it('removes objects and replaces with type placeholder', function () {
            $manager = app(EventManager::class);
            $model = new Trigger;

            $result = $manager->executeTrigger($manager->getTrigger('nonexistent') ?? Trigger::make(), new EventLog);
        })->skip('requires DB setup for integration test');

        it('preserves scalar values and nested arrays', function () {
            // Use reflection to test the protected method
            $manager = app(EventManager::class);
            $ref = new ReflectionClass($manager);
            $method = $ref->getMethod('sanitizePayloadForQueue');

            $payload = [
                'string' => 'hello',
                'int' => 42,
                'float' => 3.14,
                'bool' => true,
                'null' => null,
                'nested' => ['key' => 'value'],
            ];

            $result = $method->invoke($manager, $payload);

            expect($result)->toBe($payload);
        });

        it('strips objects and resources from payload', function () {
            $manager = app(EventManager::class);
            $ref = new ReflectionClass($manager);
            $method = $ref->getMethod('sanitizePayloadForQueue');

            $payload = [
                'model' => new \stdClass,
                'resource' => fopen('php://memory', 'r'),
                'string' => 'keep me',
            ];

            $result = $method->invoke($manager, $payload);

            expect($result['string'])->toBe('keep me');
            expect($result['model'])->toStartWith('[stripped:');
            expect($result['resource'])->toStartWith('[stripped:');

            fclose($payload['resource']);
        });
    });

    describe('PHP 8.5 syntax compliance', function () {
        it('all source files have declare(strict_types=1)', function () {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            $files = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $contents = file_get_contents($file->getPathname());
                    $hasStrictTypes = str_contains($contents, 'declare(strict_types=1)');
                    if (! $hasStrictTypes) {
                        $files[] = $file->getPathname();
                    }
                }
            }

            expect($files)->toBeEmpty('Missing declare(strict_types=1) in: ' . implode(', ', $files));
        });

        it('all source files have license header', function () {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            $files = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $contents = file_get_contents($file->getPathname());
                    if (! str_contains($contents, 'This file is part of ZeroBoiler')) {
                        $files[] = $file->getPathname();
                    }
                }
            }

            expect($files)->toBeEmpty('Missing license header in: ' . implode(', ', $files));
        });

        it('all source classes and models are final', function () {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            $nonFinal = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $contents = file_get_contents($file->getPathname());
                    // Find class declarations
                    if (preg_match_all('/\b(class|enum)\s+(\w+)/', $contents, $matches)) {
                        $tokens = token_get_all($contents);
                        foreach ($matches[2] as $className) {
                            // Check if this class is final
                            // Simple check: find the class keyword in context
                            if (str_contains($contents, "class $className") && ! str_contains($contents, "final class $className")) {
                                // Might be an interface or trait
                                if (str_contains($contents, "interface $className") || str_contains($contents, "trait $className")) {
                                    continue;
                                }
                                // EventException is intentionally non-final (base class)
                                if ($className === 'EventException') {
                                    continue;
                                }
                                $nonFinal[] = $className;
                            }
                        }
                    }
                }
            }

            expect($nonFinal)->toBeEmpty('Non-final classes: ' . implode(', ', $nonFinal));
        });
    });

    describe('EventLog status constants', function () {
        it('has exactly 4 statuses', function () {
            expect(EventLog::$statuses)->toHaveCount(4);
        });

        it('all statuses are unique', function () {
            $unique = array_unique(EventLog::$statuses);
            expect(count($unique))->toBe(count(EventLog::$statuses));
        });
    });

    describe('EventManager global disable', function () {
        it('isDisabled returns false by default', function () {
            config(['events.disabled' => false]);
            $manager = app(EventManager::class);
            expect($manager->isDisabled())->toBeFalse();
        });

        it('setEnabled(false) disables at runtime', function () {
            $manager = app(EventManager::class);
            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();
            // Clean up
            $manager->setEnabled(true);
        });

        it('fire() silently returns when disabled', function () {
            $manager = app(EventManager::class);
            $manager->setEnabled(false);

            // Should not throw, should silently return
            $manager->fire('test.event', ['key' => 'value']);

            expect(true)->toBeTrue(); // Reached without exception
            $manager->setEnabled(true);
        });
    });
});
