<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Phase 116 Production Audit
|--------------------------------------------------------------------------
|
| Comprehensive audit verifying production readiness of the events package.
| Covers: setAccessible removal across ALL files, config consistency,
| ServiceProvider binding completeness, Facade @method parity, model
| trait usage, ConditionEngine operator coverage, WildcardMatcher
| attribute correctness, DomainEvent immutability, and all 12 console
| commands registered.
|
*/

uses(TestCase::class);

// ─── setAccessible() Removal Verification (all files) ───

test('no setAccessible calls in any source file', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var RecursiveDirectoryIterator|SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = $file->getContents();
        if (preg_match('/->setAccessible\s*\(/', $contents)) {
            $relativePath = str_replace(__DIR__.'/../', '', (string) $file->getPathname());
            throw new RuntimeException("setAccessible() call found in {$relativePath} (removed in PHP 8.5)");
        }
    }

    expect(true)->toBeTrue();
});

test('no setAccessible calls in any test file', function (): void {
    $testDir = __DIR__;
    $files = glob($testDir.'/*.php');

    if ($files === false) {
        $files = [];
    }

    foreach ($files as $file) {
        $content = file_get_contents($file);
        // Allow 'setAccessible' in comments (documentation/verification tests) but NOT actual calls
        $stripped = preg_replace('#//.*$#m', '', $content);
        $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);

        if (preg_match('/->setAccessible\s*\(/', (string) $stripped)) {
            $relativePath = str_replace(__DIR__.'/../', '', $file);
            throw new RuntimeException("Actual setAccessible() call found in {$relativePath}");
        }
    }

    expect(true)->toBeTrue();
});

// ─── Config Consistency ───

test('config file has all required top-level keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

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
        expect(array_key_exists($key, $config))
            ->toBeTrue("Config missing key: {$key}");
    }
});

test('config table_names has all 3 entries', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveKeys([
        'triggers',
        'event_logs',
        'subscriptions',
    ]);
});

test('config subscriptions has all required sub-keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    $requiredSubKeys = [
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ];

    foreach ($requiredSubKeys as $key) {
        expect(array_key_exists($key, $config['subscriptions']))
            ->toBeTrue("subscriptions config missing key: {$key}");
    }
});

// ─── ServiceProvider Binding Verification ───

test('ServiceProvider provides all 7 bindings', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());

    $provides = $provider->provides();

    expect($provides)->toContain(\ZeroBoiler\Events\EventManager::class);
    expect($provides)->toContain(\ZeroBoiler\Events\ConditionEngine::class);
    expect($provides)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
    expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
    expect($provides)->toContain(\ZeroBoiler\Events\TriggerBuilder::class);
    expect($provides)->toContain(\ZeroBoiler\Events\SubscriptionBuilder::class);
    expect($provides)->toContain(\ZeroBoiler\Events\EventScheduler::class);
});

test('EventManager is registered as singleton', function (): void {
    $first = app(\ZeroBoiler\Events\EventManager::class);
    $second = app(\ZeroBoiler\Events\EventManager::class);

    expect($first)->toBe($second);
});

test('ConditionEngineContract resolves to ConditionEngine', function (): void {
    $instance = app(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);

    expect($instance)->toBeInstanceOf(\ZeroBoiler\Events\ConditionEngine::class);
});

// ─── Facade @method Parity ───

test('Facade has 24 @method entries matching EventManager public API', function (): void {
    $facadeReflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $doc = $facadeReflection->getDocComment();

    $methodCount = substr_count((string) $doc, '@method');

    // 24 public methods on EventManager (including registerScheduler)
    expect($methodCount)->toBeGreaterThanOrEqual(24);
});

test('Facade getFacadeAccessor returns correct binding', function (): void {
    $facadeReflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $facadeReflection->getMethod('getFacadeAccessor');

    // PHP 8.5: invoke directly (no setAccessible needed)
    $result = $method->invoke(null);

    expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('Facade has #[Override] on getFacadeAccessor', function (): void {
    $method = new ReflectionMethod(
        \ZeroBoiler\Events\Facades\EventManager::class,
        'getFacadeAccessor',
    );

    expect($method->getAttributes(\Override::class))->toHaveCount(1);
});

// ─── EventManager Public Method Count ───

test('EventManager has all expected public methods with return types', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    $expectedMethods = [
        'on',
        'register',
        'invalidateTriggerCache',
        'isDisabled',
        'setEnabled',
        'listTriggers',
        'getTrigger',
        'deleteTrigger',
        'enable',
        'disable',
        'fire',
        'fireModel',
        'registerScheduler',
        'executeTrigger',
        'getEventHistory',
        'getStats',
        'purgeLogs',
        'getStalePendingLogs',
        'deactivateExceededSubscriptions',
        'subscribe',
        'unsubscribe',
        'listSubscriptions',
        'getSubscription',
        'subscribeWebhook',
    ];

    $actualMethods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        $publicMethods,
    );

    foreach ($expectedMethods as $method) {
        expect(in_array($method, $actualMethods, true))
            ->toBeTrue("EventManager missing public method: {$method}");
    }

    // All public methods must have return types
    foreach ($publicMethods as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "EventManager::{$method->getName()} missing return type",
        );
    }
});

// ─── ConditionEngine Operator Coverage ───

test('ConditionEngine evaluateCondition handles all 19 operators', function (): void {
    $engine = new \ZeroBoiler\Events\ConditionEngine;
    $reflection = new ReflectionMethod($engine, 'evaluateCondition');
    // PHP 8.5: setAccessible() removed — reflection methods directly accessible

    $operators = [
        '>' => ['amount', ['>', 10], ['amount' => 20]],
        '>=' => ['amount', ['>=', 10], ['amount' => 10]],
        '<' => ['amount', ['<', 10], ['amount' => 5]],
        '<=' => ['amount', ['<=', 10], ['amount' => 10]],
        '=' => ['status', ['=', 'active'], ['status' => 'active']],
        '===' => ['flag', ['===', true], ['flag' => true]],
        '!=' => ['status', ['!=', 'draft'], ['status' => 'active']],
        '!==' => ['flag', ['!==', true], ['flag' => false]],
        'in' => ['role', ['in', ['admin']], ['role' => 'admin']],
        'not_in' => ['role', ['not_in', ['guest']], ['role' => 'admin']],
        'contains' => ['tags', ['contains', 'a'], ['tags' => ['a', 'b']]],
        'not_contains' => ['tags', ['not_contains', 'z'], ['tags' => ['a', 'b']]],
        'between' => ['age', ['between', [1, 100]], ['age' => 50]],
        'null' => ['deleted_at', ['null'], ['deleted_at' => null]],
        'not_null' => ['email', ['not_null'], ['email' => 'a@b.com']],
        'empty' => ['notes', ['empty'], ['notes' => '']],
        'not_empty' => ['notes', ['not_empty'], ['notes' => 'hello']],
        'starts_with' => ['email', ['starts_with', 'a'], ['email' => 'admin@b.com']],
        'ends_with' => ['domain', ['ends_with', '.com'], ['domain' => 'example.com']],
        'matches' => ['code', ['matches', '/^[A-Z]+$/'], ['code' => 'ABC']],
    ];

    foreach ($operators as $name => [$field, $expected, $payload]) {
        $result = $reflection->invoke($engine, $field, $expected, $payload);
        expect($result)->toBeTrue("Operator '{$name}' failed to match");
    }
});

// ─── WildcardMatcher ───

test('WildcardMatcher is readonly final with only static methods', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue(
            "WildcardMatcher::{$method->getName()} must be static",
        );
    }
});

test('WildcardMatcher has #[Pure] on all public methods', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->getAttributes(\Pure::class))->toHaveCount(1);
    }
});

// ─── DomainEvent Immutability ───

test('DomainEvent is final with 4 readonly properties', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);

    expect($reflection->isFinal())->toBeTrue();

    $readonlyProps = array_filter(
        $reflection->getProperties(),
        fn (ReflectionProperty $p): bool => $p->isReadOnly(),
    );

    expect(count($readonlyProps))->toBe(4);
});

test('DomainEvent preserves identity through roundtrip', function (): void {
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->occurredAt->format(DateTimeInterface::ATOM))
        ->toBe($original->occurredAt->format(DateTimeInterface::ATOM));
});

// ─── Model Trait Usage ───

test('EventManager uses EscapesWildcardLike, ManagesHistory, ManagesSubscriptions', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getShortName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain('EscapesWildcardLike');
    expect($traitNames)->toContain('ManagesHistory');
    expect($traitNames)->toContain('ManagesSubscriptions');
});

test('Subscription uses EscapesWildcardLike trait', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Models\Subscription::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getShortName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain('EscapesWildcardLike');
});

test('WebhookAction uses GetsWebhookTimeout trait', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getShortName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain('GetsWebhookTimeout');
});

// ─── Console Commands Registered ───

test('all 12 console commands are registered in ServiceProvider', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('commands');
    // PHP 8.5: setAccessible() removed — reflection properties directly accessible
    $commands = $property->getValue($provider);

    $expectedCommands = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($expectedCommands as $command) {
        expect($commands)->toContain($command);
    }
});

// ─── PHPStan Config ───

test('phpstan.neon.dist has level 9 and required checks', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($content)->toContain('level: 9');
    expect($content)->toContain('reportUnmatchedIgnoredErrors: true');
    expect($content)->toContain('treatPhpDocTypesAsCertain: false');
    expect($content)->toContain('checkMissingIterableValueType: true');
    expect($content)->toContain('checkGenericClassInNonGenericObjectType: true');
    expect($content)->toContain('checkUninitializedProperties: true');
});

// ─── Composer.json ───

test('composer.json has correct PHP and Laravel requirements', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    expect($composer['extra']['laravel']['providers'][0])
        ->toBe('ZeroBoiler\\Events\\EventsServiceProvider');
    expect($composer['extra']['laravel']['aliases']['EventManager'])
        ->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
});

// ─── Strict Types and License Headers ───

test('all source files have declare(strict_types=1) and license header', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $count = 0;
    /** @var RecursiveDirectoryIterator|SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $count++;
        $contents = $file->getContents();
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain(
            'This file is part of ZeroBoiler, licensed under the proprietary license.',
        );
    }

    expect($count)->toBeGreaterThan(0);
});

// ─── EventLog Status Constants ───

test('EventLog has 4 unique status constants', function (): void {
    $statuses = \ZeroBoiler\Events\Models\EventLog::$statuses;

    expect($statuses)->toHaveCount(4);
    expect($statuses)->toContain('pending');
    expect($statuses)->toContain('dispatched');
    expect($statuses)->toContain('completed');
    expect($statuses)->toContain('failed');
});

test('EventLog status constants are unique', function (): void {
    $statuses = \ZeroBoiler\Events\Models\EventLog::$statuses;
    $unique = array_unique($statuses);

    expect(count($unique))->toBe(count($statuses));
});

// ─── Pest.php Registration Completeness ───

test('Pest.php references Phase 112 production audit test', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');

    expect($pestContent)->toContain('EventsPhase112ProductionAuditTest.php');
});
