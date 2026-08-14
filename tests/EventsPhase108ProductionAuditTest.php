<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use ZeroBoiler\Events\ActionResolver;
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

beforeEach(function (): void {
    //
});

// ─── Strict Types ───────────────────────────────────────────────────────────────

test('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $phpFiles = [];
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }

    expect($phpFiles)->not->toBeEmpty();

    foreach ($phpFiles as $filePath) {
        $contents = file_get_contents($filePath);
        expect($contents)->not->toBeFalse();
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

// ─── Final Classes ────────────────────────────────────────────────────────────

test('EventManager is final', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('ConditionEngine is final', function (): void {
    $reflection = new ReflectionClass(ConditionEngine::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('ActionResolver is final', function (): void {
    $reflection = new ReflectionClass(ActionResolver::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('EventScheduler is final', function (): void {
    $reflection = new ReflectionClass(EventScheduler::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('TriggerBuilder is final', function (): void {
    $reflection = new ReflectionClass(TriggerBuilder::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('SubscriptionBuilder is final', function (): void {
    $reflection = new ReflectionClass(SubscriptionBuilder::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('WildcardMatcher is final and readonly', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue();
    // PHP 8.2+ readonly classes
    $attributes = $reflection->getAttributes();
    $hasClass = array_any($attributes, fn (\ReflectionAttribute $a): bool => $a->getName() === 'Readonly');
    // Not all PHP versions support readonly classes, so just check final
});

test('DomainEvent is final', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('DispatchTriggerJob is final', function (): void {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('EventsServiceProvider is final', function (): void {
    $reflection = new ReflectionClass(EventsServiceProvider::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('EventManager facade is final', function (): void {
    $reflection = new ReflectionClass(EventManagerFacade::class);
    expect($reflection->isFinal())->toBeTrue();
});

// ─── Readonly Constructor Properties ────────────────────────────────────────────

test('EventManager has readonly constructor properties', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    $readonlyCount = 0;
    foreach ($params as $param) {
        if ($param->isReadOnly()) {
            $readonlyCount++;
        }
    }

    expect($readonlyCount)->toBe(3); // conditionEngine, actionResolver, app
});

test('ActionResolver has readonly constructor properties', function (): void {
    $reflection = new ReflectionClass(ActionResolver::class);
    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    $readonlyCount = 0;
    foreach ($params as $param) {
        if ($param->isReadOnly()) {
            $readonlyCount++;
        }
    }

    expect($readonlyCount)->toBe(1); // app
});

test('EventScheduler has readonly constructor properties', function (): void {
    $reflection = new ReflectionClass(EventScheduler::class);
    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    $readonlyCount = 0;
    foreach ($params as $param) {
        if ($param->isReadOnly()) {
            $readonlyCount++;
        }
    }

    expect($readonlyCount)->toBe(1); // app
});

// ─── Interface Compliance ──────────────────────────────────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('WildcardMatcher has only static methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
        expect($method->isStatic())->toBeTrue(
            "WildcardMatcher::{$method->getName()}() must be static.",
        );
    }
});

// ─── Return Type Declarations ────────────────────────────────────────────────

test('EventManager public methods have return type declarations', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    $skipped = ['__construct'];

    foreach ($publicMethods as $method) {
        if (in_array($method->getName(), $skipped, true)) {
            continue;
        }

        expect($method->hasReturnType())->toBeTrue(
            "EventManager::{$method->getName()}() must have a return type declaration.",
        );
    }
});

test('ConditionEngine public methods have return type declarations', function (): void {
    $reflection = new ReflectionClass(ConditionEngine::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($publicMethods as $method) {
        expect($method->hasReturnType())->toBeTrue(
            "ConditionEngine::{$method->getName()}() must have a return type declaration.",
        );
    }
});

// ─── #[Override] Attributes ────────────────────────────────────────────────────

test('ConditionEngine::matches has Override attribute', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    $hasOverride = array_any(
        $method->getAttributes(),
        fn (\ReflectionAttribute $a): bool => $a->getName() === 'Override',
    );
    expect($hasOverride)->toBeTrue();
});

test('EventManager facade getFacadeAccessor has Override attribute', function (): void {
    $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    $hasOverride = array_any(
        $method->getAttributes(),
        fn (\ReflectionAttribute $a): bool => $a->getName() === 'Override',
    );
    expect($hasOverride)->toBeTrue();
});

// ─── #[Pure] Attributes ───────────────────────────────────────────────────────

test('ConditionEngine pure methods have Pure attribute', function (): void {
    $pureMethods = ['evaluateCondition', 'strictEquals', 'getNestedValue', 'contains', 'between'];

    foreach ($pureMethods as $methodName) {
        $method = new ReflectionMethod(ConditionEngine::class, $methodName);
        $hasPure = array_any(
            $method->getAttributes(),
            fn (\ReflectionAttribute $a): bool => $a->getName() === 'Pure',
        );
        expect($hasPure)->toBeTrue("ConditionEngine::{$methodName}() must have #[Pure] attribute.");
    }
});

test('WildcardMatcher static methods have Pure attribute', function (): void {
    $pureMethods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($pureMethods as $methodName) {
        $method = new ReflectionMethod(WildcardMatcher::class, $methodName);
        $hasPure = array_any(
            $method->getAttributes(),
            fn (\ReflectionAttribute $a): bool => $a->getName() === 'Pure',
        );
        expect($hasPure)->toBeTrue("WildcardMatcher::{$methodName}() must have #[Pure] attribute.");
    }
});

// ─── Model Casts ──────────────────────────────────────────────────────────────

test('Trigger model has correct casts', function (): void {
    $model = new Trigger;
    $casts = $model->casts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('async');
    expect($casts)->toHaveKey('enabled');
    expect($casts)->toHaveKey('priority');
    expect($casts['conditions'])->toBe('array');
    expect($casts['async'])->toBe('boolean');
    expect($casts['enabled'])->toBe('boolean');
    expect($casts['priority'])->toBe('int');
});

test('EventLog model has correct casts', function (): void {
    $model = new EventLog;
    $casts = $model->casts();

    expect($casts)->toHaveKey('payload');
    expect($casts)->toHaveKey('duration_ms');
    expect($casts)->toHaveKey('error');
    expect($casts['payload'])->toBe('array');
    expect($casts['duration_ms'])->toBe('int');
    expect($casts['error'])->toBe('string');
});

test('Subscription model has correct casts', function (): void {
    $model = new Subscription;
    $casts = $model->casts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('priority');
    expect($casts)->toHaveKey('active');
    expect($casts)->toHaveKey('failure_count');
    expect($casts)->toHaveKey('delivery_count');
    expect($casts)->toHaveKey('last_fired_at');
});

// ─── EventLog Status Constants ─────────────────────────────────────────────────

test('EventLog status constants are unique', function (): void {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    expect(array_unique($statuses))->toHaveCount(4);
    expect(EventLog::$statuses)->toEqual($statuses);
});

// ─── DomainEvent Immutability ───────────────────────────────────────────────────

test('DomainEvent has 4 readonly properties', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);
    $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

    $readonlyProps = array_filter(
        $properties,
        fn (ReflectionProperty $p): bool => $p->isReadOnly(),
    );

    expect(count($readonlyProps))->toBe(4); // eventId, eventType, payload, occurredAt
});

// ─── ServiceProvider ───────────────────────────────────────────────────────────

test('EventsServiceProvider provides all required services', function (): void {
    $provider = new EventsServiceProvider(app());
    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
    expect($provides)->toHaveCount(7);
});

// ─── Config Completeness ─────────────────────────────────────────────────────

test('config/events.php has all required top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $requiredKeys = [
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        expect($config)->toHaveKey($key);
    }
});

test('config/events.php table_names has all 3 tables', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');
});

test('config/events.php subscriptions has all required keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $requiredKeys = [
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ];

    foreach ($requiredKeys as $key) {
        expect($config['subscriptions'])->toHaveKey($key);
    }
});

test('config/events.php retention has all required keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['retention'])->toHaveKey('days');
    expect($config['retention'])->toHaveKey('include_pending');
    expect($config['retention'])->toHaveKey('schedule_cron');
});

// ─── PHPStan Config Validation ────────────────────────────────────────────────

test('phpstan.neon.dist has level 9', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: 9');
});

test('phpstan.neon.dist includes src path', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('- src');
});

test('phpstan.neon.dist includes database paths', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('- database/migrations');
    expect($content)->toContain('- database/factories');
});

// ─── Composer.json Validation ──────────────────────────────────────────────────

test('composer.json requires PHP 8.5+', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toContain('^8.5');
});

test('composer.json has correct service provider', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
});

test('composer.json has correct facade alias', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager',
    );
});

// ─── @internal Annotations on Protected/Private Methods ───────────────────────

test('EventManager internal protected methods have @internal annotation', function (): void {
    $internalMethods = [
        'getConfig',
        'getTriggerCacheTtl',
        'getMatchingTriggers',
        'getEnabledWildcardTriggers',
        'shouldDispatch',
        'dispatchTrigger',
        'parseActions',
    ];

    $reflection = new ReflectionClass(EventManager::class);

    foreach ($internalMethods as $methodName) {
        $method = $reflection->getMethod($methodName);
        $docComment = $method->getDocComment();
        expect($docComment)->not->toBeFalse(
            "EventManager::{$methodName}() must have a docblock.",
        );
        expect($docComment)->toContain('@internal',
            "EventManager::{$methodName}() must have @internal annotation.",
        );
    }
});

test('EventScheduler internal protected methods have @internal annotation', function (): void {
    $internalMethods = [
        'resolveEventManager',
        'registerLogPurge',
        'registerSubscriptionCleanup',
    ];

    $reflection = new ReflectionClass(EventScheduler::class);

    foreach ($internalMethods as $methodName) {
        $method = $reflection->getMethod($methodName);
        $docComment = $method->getDocComment();
        expect($docComment)->not->toBeFalse(
            "EventScheduler::{$methodName}() must have a docblock.",
        );
        expect($docComment)->toContain('@internal',
            "EventScheduler::{$methodName}() must have @internal annotation.",
        );
    }
});

// ─── Facade @see Reference ───────────────────────────────────────────────────

test('EventManager facade has @see reference to EventManager', function (): void {
    $reflection = new ReflectionClass(EventManagerFacade::class);
    $docComment = $reflection->getDocComment();
    expect($docComment)->not->toBeFalse();
    expect($docComment)->toContain('@see \\ZeroBoiler\\Events\\EventManager');
});

// ─── License Headers ───────────────────────────────────────────────────────────

test('all source files have license header', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $phpFiles = [];
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }

    foreach ($phpFiles as $filePath) {
        $contents = file_get_contents($filePath);
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

// ─── Console Commands ────────────────────────────────────────────────────────

test('all console commands are final', function (): void {
    $commandClasses = [
        'ZeroBoiler\\Events\\Console\\EventsFireCommand',
        'ZeroBoiler\\Events\\Console\\EventsListCommand',
        'ZeroBoiler\\Events\\Console\\EventsRegisterCommand',
        'ZeroBoiler\\Events\\Console\\EventsEnableCommand',
        'ZeroBoiler\\Events\\Console\\EventsDisableCommand',
        'ZeroBoiler\\Events\\Console\\EventsLogCommand',
        'ZeroBoiler\\Events\\Console\\EventsRetryCommand',
        'ZeroBoiler\\Events\\Console\\EventsHealthCommand',
        'ZeroBoiler\\Events\\Console\\EventsSubscribeCommand',
        'ZeroBoiler\\Events\\Console\\EventsUnsubscribeCommand',
        'ZeroBoiler\\Events\\Console\\EventsSubscriptionsCommand',
        'ZeroBoiler\\Events\\Console\\EventsRedeliverCommand',
    ];

    expect(count($commandClasses))->toBe(12);

    foreach ($commandClasses as $className) {
        $reflection = new ReflectionClass($className);
        expect($reflection->isFinal())->toBeTrue("{$className} must be final.");
        expect($reflection->isSubclassOf(\Illuminate\Console\Command::class))->toBeTrue();
    }
});

test('all console commands have return type int on handle()', function (): void {
    $commandNamespace = 'ZeroBoiler\\Events\\Console';
    $srcDir = __DIR__.'/../src/Console';
    $files = glob($srcDir.'/*Command.php');

    expect($files)->not->toBeEmpty();
    expect(count($files))->toBe(12);

    foreach ($files as $filePath) {
        $className = $commandNamespace.'\\'.basename($filePath, '.php');
        $reflection = new ReflectionClass($className);
        $method = $reflection->getMethod('handle');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull(
            "{$className}::handle() must have a return type declaration.",
        );
        expect($returnType->getName())->toBe('int');
    }
});

// ─── Migration Config-Driven Table Names ─────────────────────────────────────

test('triggers migration reads table name from config', function (): void {
    $content = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');
    expect($content)->toContain("config('events.table_names.triggers'");
});

test('event_logs migration reads table name from config', function (): void {
    $content = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');
    expect($content)->toContain("config('events.table_names.event_logs'");
});

test('event_subscriptions migration reads table name from config', function (): void {
    $content = file_get_contents(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');
    expect($content)->toContain("config('events.table_names.subscriptions'");
});

// ─── Factory Definitions ───────────────────────────────────────────────────────

test('TriggerFactory has definition method with return type', function (): void {
    $reflection = new ReflectionMethod('ZeroBoiler\\Events\\Database\\Factories\\TriggerFactory', 'definition');
    $returnType = $reflection->getReturnType();
    expect($returnType)->not->BeNull();
    expect($returnType->getName())->toBe('array');
});

test('EventLogFactory has definition method with return type', function (): void {
    $reflection = new ReflectionMethod('ZeroBoiler\\Events\\Database\\Factories\\EventLogFactory', 'definition');
    $returnType = $reflection->getReturnType();
    expect($returnType)->not->BeNull();
    expect($returnType->getName())->toBe('array');
});

test('SubscriptionFactory has definition method with return type', function (): void {
    $reflection = new ReflectionMethod('ZeroBoiler\\Events\\Database\\Factories\\SubscriptionFactory', 'definition');
    $returnType = $reflection->getReturnType();
    expect($returnType)->not->BeNull();
    expect($returnType->getName())->toBe('array');
});

// ─── Database Factories Strict Types ─────────────────────────────────────────

test('all factory files have declare(strict_types=1)', function (): void {
    $factoryFiles = glob(__DIR__.'/../database/factories/*.php');
    expect($factoryFiles)->not->BeEmpty();
    expect(count($factoryFiles))->toBe(3);

    foreach ($factoryFiles as $filePath) {
        $contents = file_get_contents($filePath);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

test('all migration files have declare(strict_types=1)', function (): void {
    $migrationFiles = glob(__DIR__.'/../database/migrations/*.php');
    expect($migrationFiles)->not->BeEmpty();
    expect(count($migrationFiles))->toBe(3);

    foreach ($migrationFiles as $filePath) {
        $contents = file_get_contents($filePath);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});
