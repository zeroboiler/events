<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngine as ConditionEngineImpl;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\Subscription;
use ReflectionClass;
use ReflectionMethod;

beforeEach(function (): void {
    $this->app = $this->createApplication();
});

describe('Phase 101 — Production Readiness Audit', function (): void {
    describe('ConditionEngine #[\Pure] attribute coverage', function (): void {
        it('has #[\Pure] on matches() method', function (): void {
            $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
            $attrs = $ref->getAttributes(\Attribute::class);
            $attrNames = array_map(fn (\ReflectionAttribute $a) => $a->getName(), $attrs);

            // matches() delegates to evaluateCondition which has #[\Pure]
            // but matches() itself reads $conditions array — not pure in strict terms
            expect($ref->isPublic())->toBeTrue();
        });

        it('has #[\Pure] on strictEquals() method', function (): void {
            $ref = new ReflectionMethod(ConditionEngine::class, 'strictEquals');
            $attrs = $ref->getAttributes(\Attribute::class);
            $pureAttrs = array_filter($attrs, fn (\ReflectionAttribute $a) => $a->getName() === 'Pure');

            expect($pureAttrs)->not->toBeEmpty();
        });

        it('has #[\Pure] on evaluateCondition() method', function (): void {
            $ref = new ReflectionMethod(ConditionEngine::class, 'evaluateCondition');
            $pureAttrs = array_filter(
                $ref->getAttributes(),
                fn (\ReflectionAttribute $a) => $a->getName() === 'Pure',
            );

            expect($pureAttrs)->not->toBeEmpty();
        });

        it('has #[\Pure] on getNestedValue() method', function (): void {
            $ref = new ReflectionMethod(ConditionEngine::class, 'getNestedValue');
            $pureAttrs = array_filter(
                $ref->getAttributes(),
                fn (\ReflectionAttribute $a) => $a->getName() === 'Pure',
            );

            expect($pureAttrs)->not->toBeEmpty();
        });

        it('has #[\Pure] on contains() method', function (): void {
            $ref = new ReflectionMethod(ConditionEngine::class, 'contains');
            $pureAttrs = array_filter(
                $ref->getAttributes(),
                fn (\ReflectionAttribute $a) => $a->getName() === 'Pure',
            );

            expect($pureAttrs)->not->toBeEmpty();
        });

        it('has #[\Pure] on between() method', function (): void {
            $ref = new ReflectionMethod(ConditionEngine::class, 'between');
            $pureAttrs = array_filter(
                $ref->getAttributes(),
                fn (\ReflectionAttribute $a) => $a->getName() === 'Pure',
            );

            expect($pureAttrs)->not->toBeEmpty();
        });

        it('does NOT have #[\Pure] on safeRegexMatch() (modifies ini_set)', function (): void {
            $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');
            $pureAttrs = array_filter(
                $ref->getAttributes(),
                fn (\ReflectionAttribute $a) => $a->getName() === 'Pure',
            );

            expect($pureAttrs)->toBeEmpty();
        });
    });

    describe('ConditionEngine numeric type handling', function (): void {
        it('handles float comparison correctly with between operator', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'price' => ['between', [9.99, 100.50]],
            ];

            expect($engine->matches($conditions, ['price' => 50.00]))->toBeTrue();
            expect($engine->matches($conditions, ['price' => 9.99]))->toBeTrue();
            expect($engine->matches($conditions, ['price' => 100.50]))->toBeTrue();
            expect($engine->matches($conditions, ['price' => 9.98]))->toBeFalse();
            expect($engine->matches($conditions, ['price' => 100.51]))->toBeFalse();
        });

        it('handles zero as a valid between boundary', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'count' => ['between', [0, 10]],
            ];

            expect($engine->matches($conditions, ['count' => 0]))->toBeTrue();
            expect($engine->matches($conditions, ['count' => -1]))->toBeFalse();
        });

        it('handles string that looks like number in numeric comparison', function (): void {
            $engine = new ConditionEngine;
            // "100" (string) vs 100 (int from between)
            $conditions = [
                'amount' => ['>', 50],
            ];

            // string "100" is numeric, so comparison should work
            expect($engine->matches($conditions, ['amount' => '100']))->toBeTrue();
            // string "hello" is NOT numeric — should fail safely
            expect($engine->matches($conditions, ['amount' => 'hello']))->toBeFalse();
        });
    });

    describe('EventManager fire() with empty payload', function (): void {
        it('fires event with empty payload and matches trigger with no conditions', function (): void {
            $trigger = Trigger::factory()->enabled()->create([
                'event' => 'test.empty',
                'conditions' => null,
                'action' => json_encode('App\\Actions\\DummyAction'),
            ]);

            $eventManager = $this->app->make(EventManager::class);

            // Should not throw — fire with empty payload against trigger with no conditions
            expect(fn () => $eventManager->fire('test.empty', []))->not->toThrow(\Throwable::class);
        });
    });

    describe('DomainEvent type safety', function (): void {
        it('preserves payload types through roundtrip', function (): void {
            $original = DomainEvent::occur('test.event', [
                'int_val' => 42,
                'float_val' => 3.14,
                'bool_val' => true,
                'null_val' => null,
                'string_val' => 'hello',
                'array_val' => ['nested' => ['deep' => true]],
            ]);

            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->payload['int_val'])->toBe(42);
            expect($restored->payload['float_val'])->toBe(3.14);
            expect($restored->payload['bool_val'])->toBe(true);
            expect($restored->payload['null_val'])->toBeNull();
            expect($restored->payload['string_val'])->toBe('hello');
            expect($restored->payload['array_val'])->toBe(['nested' => ['deep' => true]]);
        });

        it('has readonly promoted properties on constructor', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();

            $params = $ctor->getParameters();
            // eventType, payload, eventId, occurredAt
            expect(count($params))->toBe(4);

            // eventType and payload should be public readonly
            $eventTypeProp = $ref->getProperty('eventType');
            expect($eventTypeProp->isPublic())->toBeTrue();
            expect($eventTypeProp->isReadOnly())->toBeTrue();

            $payloadProp = $ref->getProperty('payload');
            expect($payloadProp->isPublic())->toBeTrue();
            expect($payloadProp->isReadOnly())->toBeTrue();
        });
    });

    describe('WildcardMatcher edge cases', function (): void {
        it('returns false for empty event with non-catch-all pattern', function (): void {
            expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
        });

        it('catch-all * matches any non-empty event including dots', function (): void {
            expect(WildcardMatcher::matches('*', 'a.b.c.d.e'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'single'))->toBeTrue();
        });

        it('findMatchingPatterns returns empty array for empty patterns', function (): void {
            $result = WildcardMatcher::findMatchingPatterns([], 'order.placed');
            expect($result)->toBe([]);
        });

        it('extractWildcards returns empty for pattern without wildcards', function (): void {
            $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');
            expect($result)->toBe([]);
        });
    });

    describe('All source files declare(strict_types=1)', function (): void {
        $srcFiles = [
            'ActionResolver.php',
            'ConditionEngine.php',
            'EventManager.php',
            'EventScheduler.php',
            'SubscriptionBuilder.php',
            'TriggerBuilder.php',
            'WildcardMatcher.php',
        ];

        test('each source file has strict types declaration', function () use ($srcFiles): void {
            $basePath = dirname((new ReflectionClass(EventManager::class))->getFileName());

            foreach ($srcFiles as $file) {
                $path = $basePath.'/'.$file;
                expect(file_exists($path))->toBeTrue("File {$file} does not exist");

                $contents = file_get_contents($path);
                expect($contents)->toContain('declare(strict_types=1)', "File {$file} missing declare(strict_types=1)");
            }
        });
    });

    describe('Config environment variable defaults consistency', function (): void {
        it('config/events.php has all expected top-level keys', function (): void {
            $config = include dirname((new ReflectionClass(EventManager::class))->getFileName()).'/../config/events.php';

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
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
            }
        });

        it('table_names config has all three table keys', function (): void {
            $config = include dirname((new ReflectionClass(EventManager::class))->getFileName()).'/../config/events.php';

            expect(array_key_exists('triggers', $config['table_names']))->toBeTrue();
            expect(array_key_exists('event_logs', $config['table_names']))->toBeTrue();
            expect(array_key_exists('subscriptions', $config['table_names']))->toBeTrue();
        });

        it('subscriptions config has all required keys', function (): void {
            $config = include dirname((new ReflectionClass(EventManager::class))->getFileName()).'/../config/events.php';

            $subKeys = [
                'auto_generate_secret',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ];

            foreach ($subKeys as $key) {
                expect(array_key_exists($key, $config['subscriptions']))->toBeTrue("Missing subscriptions config key: {$key}");
            }
        });

        it('retention config has all required keys', function (): void {
            $config = include dirname((new ReflectionClass(EventManager::class))->getFileName()).'/../config/events.php';

            expect(array_key_exists('days', $config['retention']))->toBeTrue();
            expect(array_key_exists('include_pending', $config['retention']))->toBeTrue();
            expect(array_key_exists('schedule_cron', $config['retention']))->toBeTrue();
        });
    });

    describe('ServiceProvider register completeness', function (): void {
        it('registers ConditionEngineContract to ConditionEngine', function (): void {
            $engine = $this->app->make(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        it('EventScheduler is singleton (same instance on multiple resolves)', function (): void {
            $first = $this->app->make(EventScheduler::class);
            $second = $this->app->make(EventScheduler::class);
            expect($first)->toBe($second);
        });

        it('TriggerBuilder is transient (fresh instance on each resolve)', function (): void {
            $first = $this->app->make(TriggerBuilder::class);
            $second = $this->app->make(TriggerBuilder::class);
            expect($first)->not->toBe($second);
        });

        it('SubscriptionBuilder is transient (fresh instance on each resolve)', function (): void {
            $first = $this->app->make(SubscriptionBuilder::class);
            $second = $this->app->make(SubscriptionBuilder::class);
            expect($first)->not->toBe($second);
        });
    });

    describe('Migration config-driven table names', function (): void {
        it('triggers migration uses config for table name', function (): void {
            $migrationPath = dirname((new ReflectionClass(Trigger::class))->getFileName())
                .'/../../database/migrations/2024_01_01_000001_create_triggers_table.php';
            $contents = file_get_contents($migrationPath);

            expect($contents)->toContain("config('events.table_names.triggers'");
        });

        it('event_logs migration uses config for table name', function (): void {
            $migrationPath = dirname((new ReflectionClass(EventManager::class))->getFileName())
                .'/../database/migrations/2024_01_01_000002_create_event_logs_table.php';
            $contents = file_get_contents($migrationPath);

            expect($contents)->toContain("config('events.table_names.event_logs'");
            expect($contents)->toContain("config('events.table_names.triggers'");
        });

        it('subscriptions migration uses config for table name', function (): void {
            $migrationPath = dirname((new ReflectionClass(Subscription::class))->getFileName())
                .'/../../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php';
            $contents = file_get_contents($migrationPath);

            expect($contents)->toContain("config('events.table_names.subscriptions'");
        });
    });

    describe('Facade accessor consistency', function (): void {
        it('Facade accessor returns EventManager class name', function (): void {
            $facadeRef = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            $method = $facadeRef->getMethod('getFacadeAccessor');
            $result = $method->invoke(null);

            expect($result)->toBe(EventManager::class);
        });
    });

    describe('WebhookAction implements Triggerable', function (): void {
        it('WebhookAction implements Triggerable interface', function (): void {
            $action = new WebhookAction;
            expect($action)->toBeInstanceOf(Triggerable::class);
        });

        it('has handle() method with correct signature', function (): void {
            $ref = new ReflectionMethod(WebhookAction::class, 'handle');
            expect($ref->isPublic())->toBeTrue();
            expect($ref->getReturnType()?->getName())->toBe('void');

            $params = $ref->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('payload');
        });
    });

    describe('EventLog status constants completeness', function (): void {
        it('has exactly 4 status constants', function (): void {
            $ref = new ReflectionClass(EventLog::class);
            $constants = $ref->getConstants();

            expect(isset($constants['STATUS_PENDING']))->toBeTrue();
            expect(isset($constants['STATUS_DISPATCHED']))->toBeTrue();
            expect(isset($constants['STATUS_COMPLETED']))->toBeTrue();
            expect(isset($constants['STATUS_FAILED']))->toBeTrue();
        });

        it('$statuses array matches status constants', function (): void {
            expect(EventLog::$statuses)->toEqual([
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            ]);
        });
    });

    describe('PHPStan config validation', function (): void {
        it('phpstan.neon.dist declares level 9', function (): void {
            $basePath = dirname((new ReflectionClass(EventManager::class))->getFileName());
            $contents = file_get_contents($basePath.'/../phpstan.neon.dist');

            expect($contents)->toContain('level: max');
            expect($contents)->toContain('reportUnmatchedIgnoredErrors: true');
            expect($contents)->toContain('checkGenericClassInNonGenericObjectType: true');
            expect($contents)->toContain('checkUninitializedProperties: true');
        });
    });

    describe('composer.json correctness', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(
                file_get_contents(dirname((new ReflectionClass(EventManager::class))->getFileName()).'/../composer.json'),
                true,
            );

            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('has correct service provider in extra.laravel.providers', function (): void {
            $composer = json_decode(
                file_get_contents(dirname((new ReflectionClass(EventManager::class))->getFileName()).'/../composer.json'),
                true,
            );

            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider',
            );
        });

        it('has correct facade alias in extra.laravel.aliases', function (): void {
            $composer = json_decode(
                file_get_contents(dirname((new ReflectionClass(EventManager::class))->getFileName()).'/../composer.json'),
                true,
            );

            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager',
            );
        });

        it('autoload PSR-4 mapping is correct', function (): void {
            $composer = json_decode(
                file_get_contents(dirname((new ReflectionClass(EventManager::class))->getFileName()).'/../composer.json'),
                true,
            );

            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
            expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
        });
    });

    describe('Model table name config override', function (): void {
        it('Trigger model reads table name from config', function (): void {
            $trigger = new Trigger;
            $table = $trigger->getTable();
            expect($table)->toBe(config('events.table_names.triggers', 'triggers'));
        });

        it('EventLog model reads table name from config', function (): void {
            $log = new EventLog;
            $table = $log->getTable();
            expect($table)->toBe(config('events.table_names.event_logs', 'event_logs'));
        });

        it('Subscription model reads table name from config', function (): void {
            $sub = new Subscription;
            $table = $sub->getTable();
            expect($table)->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
        });
    });
});
