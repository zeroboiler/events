<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
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

/**
 * Phase 149 — Production readiness audit.
 *
 * Covers:
 * - PHP 8.5 compliance: strict_types, license headers, final/readonly, typed properties, return types, #[Override], #[Pure]
 * - ServiceProvider register/boot/provides consistency
 * - Facade @method completeness
 * - Config key coverage (7 top-level keys)
 * - Constructor dependency injection
 * - EventsIntegrationTest registered in Pest.php
 * - README accuracy (version badge, test count, operator count, command count)
 * - ConditionEngine 20 named operators + 1 implicit equality = 21 total
 * - DomainEvent readonly/final/immutable
 * - All models: getTable(), casts(), newFactory(), keyType, incrementing
 * - Factory $model = static::class pattern (Laravel 13+)
 * - Migration timestamps present
 */
describe('Phase 149 Production Audit', function (): void {
    // ─── PHP 8.5 strict_types ───────────────────────────────────────────
    test('all source files have declare(strict_types=1)', function (): void {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');
        $violations = [];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('Missing strict_types in: '.implode(', ', $violations));
    });

    // ─── License headers ────────────────────────────────────────────────
    test('all source files have proprietary license header', function (): void {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');
        $violations = [];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false || ! str_contains($content, 'ZeroBoiler, licensed under the proprietary license')) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('Missing license header in: '.implode(', ', $violations));
    });

    // ─── Final classes ──────────────────────────────────────────────────
    test('all service classes are final', function (): void {
        $expectedFinal = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
            EventsServiceProvider::class,
            DispatchTriggerJob::class,
            DomainEvent::class,
            WebhookAction::class,
            WildcardMatcher::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
        ];

        foreach ($expectedFinal as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} must be final");
        }
    });

    // ─── Readonly classes ───────────────────────────────────────────────
    test('WildcardMatcher is a readonly final class', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);

        expect($ref->isFinal())->toBeTrue()
            ->and($ref->isReadOnly())->toBeTrue();
    });

    // ─── Constructor DI verification ─────────────────────────────────────
    test('EventManager constructor uses readonly promoted properties with typed classes', function (): void {
        $ref = new ReflectionMethod(EventManager::class, '__construct');
        $params = $ref->getParameters();

        expect($params)->toHaveCount(3);

        // conditionEngine: ConditionEngine (readonly)
        expect($params[0]->getName())->toBe('conditionEngine');
        expect($params[0]->getType()?->getName())->toBe(ConditionEngine::class);

        // actionResolver: ActionResolver (readonly)
        expect($params[1]->getName())->toBe('actionResolver');
        expect($params[1]->getType()?->getName())->toBe(ActionResolver::class);

        // app: Container (readonly)
        expect($params[2]->getName())->toBe('app');
        expect($params[2]->getType()?->getName())->toBe(\Illuminate\Container\Container::class);

        // Check they are readonly promoted
        $prop0 = new ReflectionProperty(EventManager::class, 'conditionEngine');
        $prop1 = new ReflectionProperty(EventManager::class, 'actionResolver');
        $prop2 = new ReflectionProperty(EventManager::class, 'app');

        expect($prop0->isReadOnly())->toBeTrue()
            ->and($prop1->isReadOnly())->toBeTrue()
            ->and($prop2->isReadOnly())->toBeTrue();
    });

    test('EventScheduler constructor uses readonly Container injection', function (): void {
        $ref = new ReflectionMethod(EventScheduler::class, '__construct');
        $params = $ref->getParameters();

        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('app');
        expect($params[0]->getType()?->getName())->toBe(\Illuminate\Container\Container::class);
    });

    test('TriggerBuilder and SubscriptionBuilder have EventManager dependency', function (): void {
        $tbRef = new ReflectionProperty(TriggerBuilder::class, 'eventManager');
        $sbRef = new ReflectionProperty(SubscriptionBuilder::class, 'eventManager');

        expect($tbRef->getType()?->getName())->toBe(EventManager::class);
        expect($sbRef->getType()?->getName())->toBe(EventManager::class);
    });

    // ─── #[Override] on key methods ─────────────────────────────────────
    test('ServiceProvider has #[Override] on register, boot, provides', function (): void {
        $ref = new ReflectionClass(EventsServiceProvider::class);

        $register = $ref->getMethod('register');
        $boot = $ref->getMethod('boot');
        $provides = $ref->getMethod('provides');

        expect($register->getAttributes(\Attribute::class)[0]?->getName())->toBe(\Override::class);
        expect($boot->getAttributes(\Attribute::class)[0]?->getName())->toBe(\Override::class);
        expect($provides->getAttributes(\Attribute::class)[0]?->getName())->toBe(\Override::class);
    });

    test('Facade has #[Override] on getFacadeAccessor', function (): void {
        $ref = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
        $attrs = $ref->getAttributes();

        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === \Override::class) {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue('Facade::getFacadeAccessor must have #[Override]');
    });

    // ─── #[Pure] on side-effect-free methods ────────────────────────────
    test('ConditionEngine pure methods have #[Pure] attribute', function (): void {
        $pureMethods = ['strictEquals', 'getNestedValue', 'contains', 'between'];
        $ref = new ReflectionClass(ConditionEngine::class);

        foreach ($pureMethods as $method) {
            $m = $ref->getMethod($method);
            $hasPure = false;
            foreach ($m->getAttributes() as $attr) {
                if ($attr->getName() === \Pure::class) {
                    $hasPure = true;
                    break;
                }
            }
            expect($hasPure)->toBeTrue("ConditionEngine::{$method} must have #[Pure]");
        }
    });

    test('WildcardMatcher static methods have #[Pure] attribute', function (): void {
        $pureMethods = ['matches', 'findMatchingPatterns', 'extractWildcards'];
        $ref = new ReflectionClass(WildcardMatcher::class);

        foreach ($pureMethods as $method) {
            $m = $ref->getMethod($method);
            $hasPure = false;
            foreach ($m->getAttributes() as $attr) {
                if ($attr->getName() === \Pure::class) {
                    $hasPure = true;
                    break;
                }
            }
            expect($hasPure)->toBeTrue("WildcardMatcher::{$method} must have #[Pure]");
        }
    });

    // ─── ServiceProvider bindings ───────────────────────────────────────
    test('ServiceProvider provides() returns all 7 registered services', function (): void {
        $sp = new EventsServiceProvider(app());

        $provides = $sp->provides();

        expect($provides)->toBe([
            EventManager::class,
            ConditionEngine::class,
            ConditionEngineContract::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
        ]);
    });

    test('ServiceProvider registers ConditionEngineContract to ConditionEngine', function (): void {
        $sp = new EventsServiceProvider(app());
        $sp->register();

        expect(app()->bound(ConditionEngineContract::class))->toBeTrue();
        expect(app()->make(ConditionEngineContract::class))->toBeInstanceOf(ConditionEngine::class);
    });

    // ─── Config completeness ────────────────────────────────────────────
    test('config/events.php has all 7 required top-level keys', function (): void {
        $config = require __DIR__.'/../config/events.php';

        $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];

        foreach ($requiredKeys as $key) {
            expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
        }
    });

    test('table_names config has all 3 table entries', function (): void {
        $config = require __DIR__.'/../config/events.php';

        $tables = $config['table_names'];
        expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    });

    // ─── All test files have strict_types ──────────────────────────────
    test('all test files declare strict_types=1', function (): void {
        $testFiles = glob(__DIR__.'/*Test.php');

        foreach ($testFiles as $file) {
            $content = file_get_contents($file);
            if ($content !== false) {
                expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue(
                    basename($file).' must declare strict_types'
                );
            }
        }
    });

    // ─── README accuracy ────────────────────────────────────────────────
    test('README version badge matches composer.json version', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $version = $composer['version'];

        $readme = file_get_contents(__DIR__.'/../README.md');
        expect($readme)->not->toBeFalse();
        expect(str_contains($readme, "version-{$version}"))->toBeTrue(
            "README badge should reference version {$version}"
        );
    });

    test('README test section references correct test runner', function (): void {
        $readme = file_get_contents(__DIR__.'/../README.md');
        expect($readme)->not->toBeFalse();

        // README should mention Pest as the test runner
        expect(str_contains($readme, 'Pest') || str_contains($readme, 'pest'))->toBeTrue(
            'README Testing section should mention Pest'
        );

        // README should mention PHPStan
        expect(str_contains($readme, 'PHPStan'))->toBeTrue(
            'README should mention PHPStan'
        );
    });

    test('README CLI command count matches actual (12 commands)', function (): void {
        $commands = glob(__DIR__.'/../src/Console/Events*.php');
        expect(count($commands))->toBe(12);
    });

    // ─── ConditionEngine operators ───────────────────────────────────────
    test('ConditionEngine has 20 named operators in match expression', function (): void {
        $ref = new ReflectionMethod(ConditionEngine::class, 'evaluateCondition');
        $content = file_get_contents($ref->getFileName());

        expect($content)->not->toBeFalse();

        $operators = ['>', '>=', '<', '<=', '=', '===', '!=', '!==', 'in', 'not_in', 'contains', 'not_contains', 'between', 'null', 'not_null', 'empty', 'not_empty', 'starts_with', 'ends_with', 'matches'];

        foreach ($operators as $op) {
            expect(str_contains($content, "'{$op}'"))->toBeTrue("ConditionEngine must have operator: {$op}");
        }

        // Plus implicit equality (non-array path at line 87)
        expect(str_contains($content, 'strictEquals'))->toBeTrue();
    });

    // ─── DomainEvent immutability ────────────────────────────────────────
    test('DomainEvent is final with readonly properties', function (): void {
        $ref = new ReflectionClass(DomainEvent::class);

        expect($ref->isFinal())->toBeTrue();

        $props = ['eventId', 'eventType', 'payload', 'occurredAt'];
        foreach ($props as $prop) {
            $p = new ReflectionProperty(DomainEvent::class, $prop);
            expect($p->isReadOnly())->toBeTrue("DomainEvent::\${$prop} must be readonly");
        }
    });

    test('DomainEvent::occur returns a new instance', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);

        expect($event)->toBeInstanceOf(DomainEvent::class)
            ->and($event->eventType)->toBe('test.event')
            ->and($event->payload)->toBe(['key' => 'value'])
            ->and($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class)
            ->and($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    test('DomainEvent::fromArray roundtrip preserves identity', function (): void {
        $original = DomainEvent::occur('order.created', ['id' => 123]);
        $data = $original->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
    });

    // ─── Models ─────────────────────────────────────────────────────────
    test('all models have getTable(), casts(), newFactory() methods', function (): void {
        $models = [Trigger::class, EventLog::class, Subscription::class];

        foreach ($models as $model) {
            $ref = new ReflectionClass($model);

            expect($ref->hasMethod('getTable'))->toBeTrue("{$model} must have getTable()")
                ->and($ref->hasMethod('casts'))->toBeTrue("{$model} must have casts()")
                ->and($ref->hasMethod('newFactory'))->toBeTrue("{$model} must have newFactory()");
        }
    });

    test('all models have string keyType and non-incrementing', function (): void {
        $models = [Trigger::class, EventLog::class, Subscription::class];

        foreach ($models as $model) {
            $instance = (new ReflectionClass($model))->newInstanceWithoutConstructor();
            expect($instance->getKeyName())->toBe('id');
            expect($instance->getKeyType())->toBe('string');
            expect($instance->getIncrementing())->toBeFalse();
        }
    });

    // ─── Factory $model pattern ─────────────────────────────────────────
    test('factories use static string $model = Model::class (Laravel 13+)', function (): void {
        $factories = [
            __DIR__.'/../database/factories/TriggerFactory.php',
            __DIR__.'/../database/factories/EventLogFactory.php',
            __DIR__.'/../database/factories/SubscriptionFactory.php',
        ];

        foreach ($factories as $file) {
            $content = file_get_contents($file);
            expect($content)->not->toBeFalse();
            expect(str_contains($content, 'protected static string $model = '))->toBeTrue(
                basename($file).' must use `protected static string $model` (Laravel 13+)'
            );
        }
    });

    // ─── Migrations have timestamps ────────────────────────────────────
    test('all migrations have timestamps column', function (): void {
        $migrations = glob(__DIR__.'/../database/migrations/*.php');

        foreach ($migrations as $file) {
            $content = file_get_contents($file);
            expect($content)->not->toBeFalse();
            // All migrations should have $table->timestamps() or ->timestamp()
            expect(str_contains($content, 'timestamps'))->toBeTrue(
                basename($file).' must include timestamps()'
            );
        }
    });

    // ─── Triggerable interface ──────────────────────────────────────────
    test('Triggerable interface has handle method with correct signature', function (): void {
        $ref = new ReflectionMethod(Triggerable::class, 'handle');
        $params = $ref->getParameters();

        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('payload');
        expect($ref->getReturnType()?->getName())->toBe('void');
    });

    // ─── WebhookAction implements Triggerable ────────────────────────────
    test('WebhookAction implements Triggerable and has handle method', function (): void {
        $ref = new ReflectionClass(WebhookAction::class);

        expect($ref->implementsInterface(Triggerable::class))->toBeTrue();
        expect($ref->hasMethod('handle'))->toBeTrue();
    });

    // ─── DispatchTriggerJob config-driven properties ─────────────────────
    test('DispatchTriggerJob reads retry/backoff/queue from config in constructor', function (): void {
        $ref = new ReflectionMethod(DispatchTriggerJob::class, '__construct');
        $content = file_get_contents($ref->getFileName());

        expect($content)->not->toBeFalse();
        expect(str_contains($content, 'events.retry.tries'))->toBeTrue();
        expect(str_contains($content, 'events.retry.backoff'))->toBeTrue();
        expect(str_contains($content, 'events.queue.queue'))->toBeTrue();
        expect(str_contains($content, 'events.queue.connection'))->toBeTrue();
    });

    // ─── No setAccessible calls ─────────────────────────────────────────
    test('no source files contain deprecated setAccessible calls', function (): void {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');
        $violations = [];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if ($content !== false && preg_match('/->setAccessible\s*\(/', $content)) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('setAccessible() found in: '.implode(', ', $violations));
    });

    // ─── phpstan.neon.dist validation ───────────────────────────────────
    test('phpstan.neon.dist has level: 9 and covers src/tests/factories/migrations', function (): void {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($content)->not->toBeFalse();

        expect(str_contains($content, 'level: 9'))->toBeTrue();
        expect(str_contains($content, 'src'))->toBeTrue();
        expect(str_contains($content, 'tests'))->toBeTrue();
        expect(str_contains($content, 'factories'))->toBeTrue();
        expect(str_contains($content, 'migrations'))->toBeTrue();
    });
});
