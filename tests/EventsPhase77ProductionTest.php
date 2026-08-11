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
    $this->app = app();
    $this->app->register(EventsServiceProvider::class);
});

// ─── Phase 77: Unused Import Cleanup ────────────────────────────────────────

test('EventsHealthCommand has no unused imports — EventManager import removed', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
    $file = file_get_contents($reflection->getFileName());
    assert($file !== false);

    // Verify the file does not import EventManager (it's unused)
    expect($file)
        ->not->toContain('use ZeroBoiler\\Events\\EventManager;')
        ->toContain('use ZeroBoiler\\Events\\Models\\Trigger;');
});

// ─── Phase 77: Comprehensive Source File Audit ─────────────────────────────

test('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = iterator_to_array(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ),
        false
    );

    $phpFiles = array_filter($files, fn (SplFileInfo $f): bool => $f->getExtension() === 'php');

    foreach ($phpFiles as $file) {
        $contents = file_get_contents($file->getRealPath());
        assert($contents !== false);
        expect($contents)->toContain('declare(strict_types=1)', "File {$file->getFilename()} is missing strict_types declaration.");
    }
});

test('all core classes are final', function (): void {
    $coreClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WildcardMatcher::class,
        DomainEvent::class,
        EventsServiceProvider::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        EventManagerFacade::class,
    ];

    foreach ($coreClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())
            ->toBeTrue("Class {$class} should be final for production readiness.");
    }
});

test('all console commands are final', function (): void {
    $commandClasses = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
    ];

    foreach ($commandClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())
            ->toBeTrue("Command {$class} should be final for production readiness.");
    }
});

// ─── Phase 77: Interface Contracts ──────────────────────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function (): void {
    expect(new WebhookAction)->toBeInstanceOf(Triggerable::class);
});

test('DispatchTriggerJob implements ShouldQueue', function (): void {
    expect(new DispatchTriggerJob('test-id', 'test.event', []))
        ->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('ConditionEngineContract interface has matches() method', function (): void {
    $reflection = new ReflectionClass(ConditionEngineContract::class);
    expect($reflection->hasMethod('matches'))->toBeTrue();
    expect($reflection->getMethod('matches')->isPublic())->toBeTrue();
});

test('Triggerable interface has handle() method', function (): void {
    $reflection = new ReflectionClass(Triggerable::class);
    expect($reflection->hasMethod('handle'))->toBeTrue();
    expect($reflection->getMethod('handle')->isPublic())->toBeTrue();
});

// ─── Phase 77: Service Provider ────────────────────────────────────────────

test('ServiceProvider register method has #[Override]', function (): void {
    $reflection = new ReflectionMethod(EventsServiceProvider::class, 'register');
    $attrs = $reflection->getAttributes();
    $attrNames = array_map(fn (ReflectionAttribute $a): string => $a->getName(), $attrs);
    expect($attrNames)->toContain(\Override::class);
});

test('ServiceProvider boot method has #[Override]', function (): void {
    $reflection = new ReflectionMethod(EventsServiceProvider::class, 'boot');
    $attrs = $reflection->getAttributes();
    $attrNames = array_map(fn (ReflectionAttribute $a): string => $a->getName(), $attrs);
    expect($attrNames)->toContain(\Override::class);
});

test('ServiceProvider provides method has #[Override]', function (): void {
    $reflection = new ReflectionMethod(EventsServiceProvider::class, 'provides');
    $attrs = $reflection->getAttributes();
    $attrNames = array_map(fn (ReflectionAttribute $a): string => $a->getName(), $attrs);
    expect($attrNames)->toContain(\Override::class);
});

test('ServiceProvider singleton bindings resolve correctly', function (): void {
    // EventManager is singleton
    $a = $this->app->make(EventManager::class);
    $b = $this->app->make(EventManager::class);
    expect($a)->toBe($b);

    // ConditionEngine is singleton
    $c = $this->app->make(ConditionEngine::class);
    $d = $this->app->make(ConditionEngine::class);
    expect($c)->toBe($d);

    // ConditionEngineContract resolves to ConditionEngine
    $contract = $this->app->make(ConditionEngineContract::class);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);

    // ActionResolver is singleton
    $e = $this->app->make(ActionResolver::class);
    $f = $this->app->make(ActionResolver::class);
    expect($e)->toBe($f);
});

test('ServiceProvider transient bindings are fresh instances', function (): void {
    $a = $this->app->make(TriggerBuilder::class);
    $b = $this->app->make(TriggerBuilder::class);
    expect($a)->not->toBe($b);

    $c = $this->app->make(SubscriptionBuilder::class);
    $d = $this->app->make(SubscriptionBuilder::class);
    expect($c)->not->toBe($d);
});

test('ServiceProvider provides list contains all 6 services', function (): void {
    $provider = $this->app->getProvider(EventsServiceProvider::class);
    assert($provider instanceof EventsServiceProvider);
    $provides = $provider->provides();

    expect($provides)->toHaveCount(6);
    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
});

// ─── Phase 77: Facade ──────────────────────────────────────────────────────

test('Facade accessor returns correct class name', function (): void {
    $reflection = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    expect($reflection->isPublic())->toBeTrue();
    expect($reflection->getReturnType()?->getName())->toBe('string');

    // Verify @method count matches public API
    $docComment = $reflection->class->getDocComment();
    assert($docComment !== false);
    $methodCount = substr_count($docComment, '@method static');
    expect($methodCount)->toBe(23, 'Facade should have 23 @method annotations matching EventManager public API.');
});

// ─── Phase 77: Config Completeness ─────────────────────────────────────────

test('config has all 7 top-level sections', function (): void {
    $config = config('events');
    expect($config)->not->toBeNull();
    expect(array_keys($config))->toEqual([
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ]);
});

test('config table_names has all 3 entries', function (): void {
    $tableNames = config('events.table_names');
    expect($tableNames)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('config subscriptions has all 4 entries', function (): void {
    $subs = config('events.subscriptions');
    expect($subs)->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
    ]);
});

test('config queue has connection and queue keys', function (): void {
    $queue = config('events.queue');
    expect($queue)->toHaveKeys(['connection', 'queue']);
});

test('config retry has tries and backoff keys', function (): void {
    $retry = config('events.retry');
    expect($retry)->toHaveKeys(['tries', 'backoff']);
});

test('config retention has days and include_pending keys', function (): void {
    $retention = config('events.retention');
    expect($retention)->toHaveKeys(['days', 'include_pending']);
});

// ─── Phase 77: Models ───────────────────────────────────────────────────────

test('Trigger model has config-driven table name', function (): void {
    $trigger = new Trigger;
    $table = $trigger->getTable();
    expect($table)->toBe(config('events.table_names.triggers', 'triggers'));
});

test('EventLog model has config-driven table name', function (): void {
    $log = new EventLog;
    $table = $log->getTable();
    expect($table)->toBe(config('events.table_names.event_logs', 'event_logs'));
});

test('Subscription model has config-driven table name', function (): void {
    $sub = new Subscription;
    $table = $sub->getTable();
    expect($table)->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
});

test('all models use UUID string keys and non-incrementing', function (): void {
    foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
        $instance = new $model;
        expect($instance->getKeyType())->toBe('string');
        expect($instance->getIncrementing())->toBeFalse();
    }
});

test('EventLog has 4 status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toHaveCount(4);
});

test('model casts are properly declared', function (): void {
    // Trigger casts
    $trigger = new Trigger;
    $triggerCasts = $trigger->getCastAttributes();
    expect($triggerCasts)->toHaveKey('conditions');
    expect($triggerCasts)->toHaveKey('async');
    expect($triggerCasts)->toHaveKey('enabled');
    expect($triggerCasts)->toHaveKey('priority');

    // EventLog casts
    $log = new EventLog;
    $logCasts = $log->getCastAttributes();
    expect($logCasts)->toHaveKey('payload');
    expect($logCasts)->toHaveKey('duration_ms');
    expect($logCasts)->toHaveKey('error');

    // Subscription casts
    $sub = new Subscription;
    $subCasts = $sub->getCastAttributes();
    expect($subCasts)->toHaveKey('conditions');
    expect($subCasts)->toHaveKey('priority');
    expect($subCasts)->toHaveKey('active');
    expect($subCasts)->toHaveKey('failure_count');
    expect($subCasts)->toHaveKey('delivery_count');
    expect($subCasts)->toHaveKey('last_fired_at');
});

// ─── Phase 77: DomainEvent ──────────────────────────────────────────────────

test('DomainEvent is final and has readonly properties', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);
    expect($reflection->isFinal())->toBeTrue();

    $eventIdProp = $reflection->getProperty('eventId');
    expect($eventIdProp->isReadOnly())->toBeTrue();

    $occurredAtProp = $reflection->getProperty('occurredAt');
    expect($occurredAtProp->isReadOnly())->toBeTrue();

    $eventTypeProp = $reflection->getProperty('eventType');
    expect($eventTypeProp->isReadOnly())->toBeTrue();

    $payloadProp = $reflection->getProperty('payload');
    expect($payloadProp->isReadOnly())->toBeTrue();
});

test('DomainEvent roundtrip preserves identity', function (): void {
    $original = DomainEvent::occur('user.created', ['email' => 'test@example.com']);
    $data = $original->toArray();
    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
});

test('DomainEvent fromArray rejects empty eventType', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    DomainEvent::fromArray(['eventType' => '']);
});

// ─── Phase 77: WildcardMatcher ──────────────────────────────────────────────

test('WildcardMatcher is readonly and final', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

test('WildcardMatcher all static methods have #[Pure]', function (): void {
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($methods as $method) {
        $reflection = new ReflectionMethod(WildcardMatcher::class, $method);
        $attrs = $reflection->getAttributes();
        $attrNames = array_map(fn (ReflectionAttribute $a): string => $a->getName(), $attrs);
        expect($attrNames)->toContain(\Pure::class, "WildcardMatcher::{$method}() should have #[Pure] attribute.");
    }
});

test('WildcardMatcher comprehensive patterns', function (): void {
    // Exact
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();

    // Single segment
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

    // Cross segment
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();

    // Catch-all
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();

    // Empty
    expect(WildcardMatcher::matches('', ''))->toBeFalse();
});

// ─── Phase 77: ConditionEngine Full Operator Matrix ─────────────────────────

test('ConditionEngine full 19-operator matrix', function (): void {
    $engine = new ConditionEngine;
    $payload = [
        'amount' => 100,
        'status' => 'active',
        'tags' => ['urgent', 'important'],
        'notes' => 'Hello World',
        'deleted_at' => null,
        'email' => 'admin@example.com',
        'role' => 'admin',
        'code' => 'ABC-1234',
        'empty_field' => '',
        'non_empty_field' => 'value',
    ];

    // Comparison operators
    expect($engine->matches(['amount' => ['>', 50]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 100]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 200]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 100]], $payload))->toBeTrue();

    // Equality
    expect($engine->matches(['status' => 'active'], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['=', 'active']], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['===', 'active']], $payload))->toBeTrue();

    // Inequality
    expect($engine->matches(['status' => ['!=', 'inactive']], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['!==', 'inactive']], $payload))->toBeTrue();

    // Array operators
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], $payload))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['guest']]], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['contains', 'urgent']], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['not_contains', 'spam']], $payload))->toBeTrue();

    // Between
    expect($engine->matches(['amount' => ['between', [50, 200]]], $payload))->toBeTrue();

    // Null checks
    expect($engine->matches(['deleted_at' => ['null']], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['not_null']], $payload))->toBeTrue();

    // Empty checks
    expect($engine->matches(['empty_field' => ['empty']], $payload))->toBeTrue();
    expect($engine->matches(['non_empty_field' => ['not_empty']], $payload))->toBeTrue();

    // String operators
    expect($engine->matches(['email' => ['starts_with', 'admin']], $payload))->toBeTrue();
    expect($engine->matches(['email' => ['ends_with', 'example.com']], $payload))->toBeTrue();
    expect($engine->matches(['code' => ['matches', '/^[A-Z]+-\\d+$/']], $payload))->toBeTrue();
});

test('ConditionEngine empty conditions returns true', function (): void {
    $engine = new ConditionEngine;
    expect($engine->matches([], ['anything' => 'value']))->toBeTrue();
});

test('ConditionEngine AND logic — all conditions must match', function (): void {
    $engine = new ConditionEngine;
    $payload = ['amount' => 100, 'status' => 'active'];

    expect($engine->matches([
        'amount' => ['>', 50],
        'status' => 'active',
    ], $payload))->toBeTrue();

    expect($engine->matches([
        'amount' => ['>', 200], // fails
        'status' => 'active',
    ], $payload))->toBeFalse();
});

// ─── Phase 77: EscapesWildcardLike ──────────────────────────────────────────

test('EscapesWildcardLike SQL escaping', function (): void {
    // We can't directly instantiate a trait, so we test via Subscription
    $sub = new Subscription;

    // Non-wildcard should return null (use where clause instead)
    $reflection = new ReflectionMethod(Subscription::class, 'wildcardToLike');
    $reflection->setAccessible(true);

    expect($reflection->invoke($sub, 'order.placed'))->toBeNull();
    expect($reflection->invoke($sub, 'order.*'))->toBe('order.%');
    expect($reflection->invoke($sub, 'order.**'))->toBe('order.%');
    expect($reflection->invoke($sub, '*.order.*'))->toBe('%.order.%');

    // Special chars
    expect($reflection->invoke($sub, 'test%event'))->toBeNull(); // no wildcard
    expect($reflection->invoke($sub, 'test%event*'))->toBe('test\%event%');
    expect($reflection->invoke($sub, 'test_event*'))->toBe('test\_event%');
    expect($reflection->invoke($sub, 'test\\event*'))->toBe('test\\\\event%');
});

// ─── Phase 77: Migrations & Factories ──────────────────────────────────────

test('all 3 migration files exist and have up/down methods', function (): void {
    $migrations = [
        __DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php',
        __DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php',
        __DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php',
    ];

    foreach ($migrations as $migration) {
        expect(file_exists($migration))->toBeTrue("Migration {$migration} must exist.");
        $contents = file_get_contents($migration);
        assert($contents !== false);
        expect($contents)->toContain('public function up(): void');
        expect($contents)->toContain('public function down(): void');
    }
});

test('all 3 factory files exist and have definition method', function (): void {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factory) {
        $reflection = new ReflectionClass($factory);
        expect($reflection->hasMethod('definition'))->toBeTrue("Factory {$factory} must have a definition() method.");
    }
});

// ─── Phase 77: Composer & Autoload ─────────────────────────────────────────

test('composer.json autoload PSR-4 is correct', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
});

test('composer.json extra.laravel providers is correct', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider'
    );
});

test('composer.json PHP version is ^8.5', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
});

test('composer.json PHPStan version is ^2.2', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require-dev']['phpstan/phpstan'])->toBe('^2.2');
});

// ─── Phase 77: PHPStan Config ──────────────────────────────────────────────

test('phpstan.neon.dist has level 9', function (): void {
    $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    assert($config !== false);
    expect($config)->toContain('level: 9');
});

test('phpstan.neon.dist has baselineFile', function (): void {
    $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    assert($config !== false);
    expect($config)->toContain('baselineFile');
});

test('phpstan.neon.dist has src path', function (): void {
    $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    assert($config !== false);
    expect($config)->toContain('- src');
});

// ─── Phase 77: Version Consistency ──────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');
    assert($readme !== false);

    $version = $composer['version'];
    expect($version)->toBeString();
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
    expect($readme)->toContain("version-{$version}");
});

// ─── Phase 77: EventManager Public API ─────────────────────────────────────

test('EventManager has 23 public methods', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $publicMethods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! $m->isStatic() && $m->getName() !== '__construct'
    );

    expect(count($publicMethods))->toBe(23);

    $methodNames = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);
    $expectedMethods = [
        'on', 'register', 'fire', 'fireModel',
        'enable', 'disable', 'invalidateTriggerCache',
        'isDisabled', 'setEnabled',
        'listTriggers', 'getTrigger', 'deleteTrigger',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription', 'subscribeWebhook',
        'getEventHistory', 'getStats', 'purgeLogs',
        'getStalePendingLogs', 'deactivateExceededSubscriptions',
        'executeTrigger',
    ];

    foreach ($expectedMethods as $method) {
        expect($methodNames)->toContain($method);
    }
});

// ─── Phase 77: Readonly Promoted Constructor Properties ───────────────────

test('EventManager has readonly promoted constructor properties', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $constructor = $reflection->getConstructor();
    assert($constructor !== null);

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);

    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue();
    }
});

test('ActionResolver has readonly promoted constructor properties', function (): void {
    $reflection = new ReflectionClass(ActionResolver::class);
    $constructor = $reflection->getConstructor();
    assert($constructor !== null);

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->isPromoted())->toBeTrue();
});

// ─── Phase 77: Fluent Interface ────────────────────────────────────────────

test('TriggerBuilder fluent interface methods return self', function (): void {
    $reflection = new ReflectionClass(TriggerBuilder::class);
    $fluentMethods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];

    foreach ($fluentMethods as $method) {
        $methodReflection = $reflection->getMethod($method);
        $returnType = $methodReflection->getReturnType();
        expect($returnType?->getName())->toBe('self', "TriggerBuilder::{$method}() should return self.");
    }
});

test('SubscriptionBuilder fluent interface methods return self', function (): void {
    $reflection = new ReflectionClass(SubscriptionBuilder::class);
    $fluentMethods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];

    foreach ($fluentMethods as $method) {
        $methodReflection = $reflection->getMethod($method);
        $returnType = $methodReflection->getReturnType();
        expect($returnType?->getName())->toBe('self', "SubscriptionBuilder::{$method}() should return self.");
    }
});

// ─── Phase 77: File Headers ─────────────────────────────────────────────────

test('all source files have license header', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = iterator_to_array(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ),
        false
    );

    $phpFiles = array_filter($files, fn (SplFileInfo $f): bool => $f->getExtension() === 'php');

    foreach ($phpFiles as $file) {
        $contents = file_get_contents($file->getRealPath());
        assert($contents !== false);
        expect($contents)
            ->toContain('This file is part of ZeroBoiler', "File {$file->getFilename()} is missing the license header.");
    }
});

// ─── Phase 77: Subscription Sign/Match ─────────────────────────────────────

test('Subscription signPayload handles null/empty secret', function (): void {
    $sub = new Subscription(['secret' => null]);
    expect($sub->signPayload('test-payload'))->toBe('');

    $sub2 = new Subscription(['secret' => '']);
    expect($sub2->signPayload('test-payload'))->toBe('');
});

test('Subscription signPayload produces deterministic signature', function (): void {
    $sub = new Subscription(['secret' => 'test-secret-key']);
    $sig1 = $sub->signPayload('test-payload');
    $sig2 = $sub->signPayload('test-payload');
    expect($sig1)->toBe($sig2);
    expect($sig1)->not->toBeEmpty();
});

test('Subscription matchesEvent with wildcards', function (): void {
    $sub = new Subscription(['event' => 'order.*']);
    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
    expect($sub->matchesEvent('user.placed'))->toBeFalse();

    $sub2 = new Subscription(['event' => 'order.**']);
    expect($sub2->matchesEvent('order.placed'))->toBeTrue();
    expect($sub2->matchesEvent('order.placed.extra'))->toBeTrue();
});

// ─── Phase 77: getStats Zero State ──────────────────────────────────────────

test('getStats returns valid zero state structure', function (): void {
    $manager = $this->app->make(EventManager::class);
    $stats = $manager->getStats();

    expect($stats)->toHaveKeys([
        'total_logs', 'total_triggers', 'active_triggers',
        'completed', 'failed', 'pending', 'dispatched',
        'success_rate', 'failure_rate', 'avg_duration_ms',
        'top_events', 'top_failed_events',
    ]);

    // Types
    expect($stats['total_logs'])->toBeInt();
    expect($stats['total_triggers'])->toBeInt();
    expect($stats['active_triggers'])->toBeInt();
    expect($stats['completed'])->toBeInt();
    expect($stats['failed'])->toBeInt();
    expect($stats['pending'])->toBeInt();
    expect($stats['dispatched'])->toBeInt();
    expect($stats['top_events'])->toBeArray();
    expect($stats['top_failed_events'])->toBeArray();
});

// ─── Phase 77: Subscription Builder Validation ──────────────────────────────

test('SubscriptionBuilder rejects non-HTTP(S) URLs', function (): void {
    $manager = $this->app->make(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->subscribe('order.placed', 'ftp://evil.com/hooks')
        ->save();
});

test('SubscriptionBuilder requires non-empty event name', function (): void {
    $manager = $this->app->make(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->subscribe('', 'https://example.com/hooks')
        ->save();
});

test('SubscriptionBuilder requires non-empty URL', function (): void {
    $manager = $this->app->make(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->subscribe('order.placed', '')
        ->save();
});

// ─── Phase 77: EventManager fire/fireModel validation ───────────────────────

test('fire rejects empty event name', function (): void {
    $manager = $this->app->make(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->fire('');
});

test('fireModel rejects empty model class', function (): void {
    $manager = $this->app->make(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->fireModel('', 'created', new stdClass);
});

test('fireModel rejects empty action', function (): void {
    $manager = $this->app->make(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->fireModel('App\\Models\\Order', '', new stdClass);
});

// ─── Phase 77: TriggerBuilder Validation ────────────────────────────────────

test('TriggerBuilder requires non-empty event name', function (): void {
    $manager = $this->app->make(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->on('')
        ->action(TestAction::class)
        ->save();
});

test('TriggerBuilder requires at least one action', function (): void {
    $manager = $this->app->make(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->on('test.event')
        ->save();
});

// ─── Phase 77: Cache Invalidation Lifecycle ────────────────────────────────

test('cache invalidation on enable/disable', function (): void {
    $manager = $this->app->make(EventManager::class);

    // Should not throw — enable/disable non-existent triggers
    expect($manager->enable('non-existent-id'))->toBeFalse();
    expect($manager->disable('non-existent-id'))->toBeFalse();

    // invalidateTriggerCache should not throw
    $manager->invalidateTriggerCache();
    expect(true)->toBeTrue();
});

// ─── Phase 77: DispatchTriggerJob Config Properties ──────────────────────────

test('DispatchTriggerJob reads config at construction time', function (): void {
    config(['events.retry.tries' => 5]);
    $job = new DispatchTriggerJob('id', 'event', []);
    expect($job->tries)->toBe(5);

    config(['events.retry.tries' => 2]);
    $job2 = new DispatchTriggerJob('id', 'event', []);
    expect($job2->tries)->toBe(2);

    // Verify backoff array format
    config(['events.retry.backoff' => [10, 20, 30]]);
    $job3 = new DispatchTriggerJob('id', 'event', []);
    expect($job3->backoff)->toEqual([10, 20, 30]);

    // Verify string format
    config(['events.retry.backoff' => '10,20,30']);
    $job4 = new DispatchTriggerJob('id', 'event', []);
    expect($job4->backoff)->toEqual([10, 20, 30]);
});

// ─── Phase 77: ActionResolver Errors ───────────────────────────────────────

test('ActionResolver throws for non-existent class', function (): void {
    $resolver = new ActionResolver($this->app);

    $this->expectException(\InvalidArgumentException::class);
    $resolver->resolve('NonExistent\\ActionClass');
});
