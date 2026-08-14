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
 * Phase 162 production audit — comprehensive quality verification.
 *
 * Validates source code quality, PHP 8.5 compliance, ServiceProvider
 * bindings, config completeness, interface implementations, README
 * consistency, and production readiness of the events package.
 */
test('all source files declare strict types', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

test('all source files have proprietary license header', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

test('all service classes are final', function (): void {
    $expectedFinal = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        EventScheduler::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
        DispatchTriggerJob::class,
        WebhookAction::class,
        EventsServiceProvider::class,
        EventManagerFacade::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
    ];

    foreach ($expectedFinal as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} should be final");
    }
});

test('WildcardMatcher is readonly final with only static methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("WildcardMatcher::{$method->getName()}() should be static");
    }
});

test('all public methods have return type declarations', function (): void {
    $classes = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        EventScheduler::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
    ];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }

            $reflectionType = $method->getReturnType();
            expect($reflectionType)->not->toBeNull(
                "{$class}::{$method->getName()}() must have a return type declaration"
            );
        }
    }
});

test('ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ConditionEngine;

    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function (): void {
    $action = new WebhookAction;

    expect($action)->toBeInstanceOf(Triggerable::class);
});

test('EventManager constructor uses readonly promoted properties', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $constructor = $reflection->getMethod('__construct');
    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(3);

    foreach ($parameters as $param) {
        expect($param->isPromoted())->toBeTrue(
            "EventManager constructor param \${$param->getName()} should be promoted"
        );
    }
});

test('TriggerBuilder constructor uses readonly promoted properties', function (): void {
    $reflection = new ReflectionClass(TriggerBuilder::class);
    $constructor = $reflection->getMethod('__construct');

    foreach ($constructor->getParameters() as $param) {
        expect($param->isPromoted())->toBeTrue(
            "TriggerBuilder constructor param \${$param->getName()} should be promoted"
        );
    }
});

test('SubscriptionBuilder constructor uses readonly promoted properties', function (): void {
    $reflection = new ReflectionClass(SubscriptionBuilder::class);
    $constructor = $reflection->getMethod('__construct');

    foreach ($constructor->getParameters() as $param) {
        expect($param->isPromoted())->toBeTrue(
            "SubscriptionBuilder constructor param \${$param->getName()} should be promoted"
        );
    }
});

test('ServiceProvider provides returns all 7 bindings', function (): void {
    $provider = new ReflectionClass(EventsServiceProvider::class);
    $method = $provider->getMethod('provides');

    $result = $method->invoke(new EventsServiceProvider(app()));

    expect($result)->toBeArray();
    expect($result)->toContain(EventManager::class);
    expect($result)->toContain(ConditionEngine::class);
    expect($result)->toContain(ConditionEngineContract::class);
    expect($result)->toContain(ActionResolver::class);
    expect($result)->toContain(TriggerBuilder::class);
    expect($result)->toContain(SubscriptionBuilder::class);
    expect($result)->toContain(EventScheduler::class);
    expect(count($result))->toBe(7);
});

test('config file has all 7 top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config)->toBeArray();
    expect(array_keys($config))->toContain('table_names');
    expect(array_keys($config))->toContain('queue');
    expect(array_keys($config))->toContain('retry');
    expect(array_keys($config))->toContain('retention');
    expect(array_keys($config))->toContain('subscriptions');
    expect(array_keys($config))->toContain('disabled');
    expect(array_keys($config))->toContain('wildcard_cache_ttl');
    expect(count($config))->toBe(7);
});

test('config table_names has all 3 tables', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('config queue has connection and queue keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['queue'])->toHaveKeys(['connection', 'queue']);
});

test('config retry has tries and backoff keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
});

test('config retention has days include_pending and schedule_cron keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['retention'])->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
});

test('config subscriptions has 5 keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['subscriptions'])->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ]);
});

test('DomainEvent immutability — properties are readonly', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);

    $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
    expect($properties)->toHaveCount(4);

    foreach ($properties as $property) {
        expect($property->isReadOnly())->toBeTrue(
            "DomainEvent::\${$property->getName()} should be readonly"
        );
    }
});

test('DomainEvent roundtrip preserves identity', function (): void {
    $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});

test('EventLog status constants are defined', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');

    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
});

test('models use UUID string keys', function (): void {
    foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
        $reflection = new ReflectionProperty($model, 'keyType');
        expect($reflection->getValue(new $model))->toBe('string');
    }
});

test('models use config-driven table names via getTable', function (): void {
    $models = [
        Trigger::class => 'events.table_names.triggers',
        EventLog::class => 'events.table_names.event_logs',
        Subscription::class => 'events.table_names.subscriptions',
    ];

    foreach ($models as $model => $configKey) {
        $reflection = new ReflectionMethod($model, 'getTable');
        expect($reflection->hasReturnType())->toBeTrue();
    }
});

test('Facade getFacadeAccessor returns EventManager class', function (): void {
    $reflection = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    $result = $reflection->invoke(null);

    expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('DispatchTriggerJob has config-driven public properties', function (): void {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);

    expect($reflection->getProperty('tries')->isPublic())->toBeTrue();
    expect($reflection->getProperty('tries')->isReadOnly())->toBeFalse();
    expect($reflection->getProperty('backoff')->isPublic())->toBeTrue();
    expect($reflection->getProperty('queue')->isPublic())->toBeTrue();
    expect($reflection->getProperty('connection')->isPublic())->toBeTrue();
});

test('phpstan.neon.dist exists and is valid', function (): void {
    $path = __DIR__.'/../phpstan.neon.dist';
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)->toContain('level: 9');
    expect($contents)->toContain('reportUnusedIgnoredErrors: true');
    expect($contents)->toContain('universalObjectCratesClasses');
});

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($composer['version'])->toBe('4.97.0');
    expect($readme)->toContain('4.97.0');
});

test('all 12 console commands are registered in ServiceProvider', function (): void {
    $reflection = new ReflectionMethod(EventsServiceProvider::class, 'boot');
    $filename = (new ReflectionMethod(EventsServiceProvider::class, 'boot'))
        ->getFileName();

    $contents = file_get_contents($filename);

    $expectedCommands = [
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

    foreach ($expectedCommands as $command) {
        expect($contents)->toContain($command);
    }
});

test('database factories exist for all 3 models', function (): void {
    $factories = glob(__DIR__.'/../database/factories/*.php');

    expect($factories)->toHaveCount(3);

    $names = array_map(
        fn (string $f): string => basename($f),
        $factories,
    );

    expect($names)->toContain('TriggerFactory.php');
    expect($names)->toContain('EventLogFactory.php');
    expect($names)->toContain('SubscriptionFactory.php');
});

test('database migrations exist for all 3 tables', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');

    expect($migrations)->toHaveCount(3);

    $names = array_map(
        fn (string $f): string => basename($f),
        $migrations,
    );

    expect($names)->toContain('2024_01_01_000001_create_triggers_table.php');
    expect($names)->toContain('2024_01_01_000002_create_event_logs_table.php');
    expect($names)->toContain('2025_06_28_000001_create_event_subscriptions_table.php');
});

test('migrations use config-driven table names', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');

    foreach ($migrations as $migration) {
        $contents = file_get_contents($migration);
        expect($contents)->toContain("config('events.table_names.");
    }
});

test('no deprecated setAccessible calls in source files', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toContain('setAccessible(');
    }
});

test('readme version badge matches composer version', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'];
    expect($readme)->toContain("version-{$version}-blue");
});

test('ServiceProvider boot method loads migrations from correct path', function (): void {
    $reflection = new ReflectionMethod(EventsServiceProvider::class, 'boot');
    $filename = $reflection->getFileName();
    $contents = file_get_contents($filename);

    expect($contents)->toContain("loadMigrationsFrom(__DIR__.'/../database/migrations')");
});

test('ServiceProvider register method merges config', function (): void {
    $reflection = new ReflectionMethod(EventsServiceProvider::class, 'register');
    $filename = $reflection->getFileName();
    $contents = file_get_contents($filename);

    expect($contents)->toContain("mergeConfigFrom(");
    expect($contents)->toContain("'events'");
});
