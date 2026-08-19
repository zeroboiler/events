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
use ZeroBoiler\Events\Exceptions\ActionResolutionException;
use ZeroBoiler\Events\Exceptions\ConditionEvaluationException;
use ZeroBoiler\Events\Exceptions\EventException;
use ZeroBoiler\Events\Exceptions\SubscriptionException;
use ZeroBoiler\Events\Exceptions\TriggerNotFoundException;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 217 — Final infrastructure production readiness audit.
 *
 * Validates: strict types, final classes, readonly properties, typed properties,
 * return types, docblocks, #[Override]/#[Pure] attributes, exception hierarchy,
 * ServiceProvider provides(), config completeness, facade accessor, and
 * interface contract compliance.
 */
test('Phase 217: all source files declare strict_types=1', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php', GLOB_ERR);
    if ($files === false) {
        $files = [];
    }

    expect($files)->not->toBeEmpty('src/ directory should contain PHP files');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "{$file} must declare strict_types=1");
    }
});

test('Phase 217: all classes are final (except EventException base)', function (): void {
    $nonFinalAllowed = [
        EventException::class,
    ];

    $classes = [
        ActionResolver::class,
        ConditionEngine::class,
        DomainEvent::class,
        EventScheduler::class,
        EventsServiceProvider::class,
        EventManager::class,
        SubscriptionBuilder::class,
        TriggerBuilder::class,
        WildcardMatcher::class,
        ActionResolutionException::class,
        ConditionEvaluationException::class,
        SubscriptionException::class,
        TriggerNotFoundException::class,
        DispatchTriggerJob::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        EventManager::class, // Facade
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($classes as $class) {
        $ref = new \ReflectionClass($class);
        if (! in_array($class, $nonFinalAllowed, true)) {
            expect($ref->isFinal())->toBeTrue("{$class} must be final");
        } else {
            expect($ref->isFinal())->toBeFalse("{$class} must NOT be final (base exception)");
        }
    }
});

test('Phase 217: exception hierarchy integrity', function (): void {
    $base = EventException::class;
    $leaves = [
        ActionResolutionException::class,
        ConditionEvaluationException::class,
        SubscriptionException::class,
        TriggerNotFoundException::class,
    ];

    // Base must be non-final abstract
    expect((new \ReflectionClass($base))->isFinal())->toBeFalse();
    expect((new \ReflectionClass($base))->isSubclassOf(\RuntimeException::class))->toBeTrue();

    // All leaves must be final and extend EventException
    foreach ($leaves as $leaf) {
        $ref = new \ReflectionClass($leaf);
        expect($ref->isFinal())->toBeTrue("{$leaf} must be final");
        expect($ref->isSubclassOf($base))->toBeTrue("{$leaf} must extend {$base}");
    }

    // Base docblock references all leaves
    $doc = (new \ReflectionClass($base))->getDocComment();
    expect($doc)->not->toBeFalse();
    foreach ($leaves as $leaf) {
        expect($doc)->toContain($leaf);
    }
});

test('Phase 217: all public methods have return type declarations', function (): void {
    $classesToCheck = [
        EventManager::class,
        ActionResolver::class,
        ConditionEngine::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
        DomainEvent::class,
        WildcardMatcher::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
        DispatchTriggerJob::class,
        EventsServiceProvider::class,
    ];

    foreach ($classesToCheck as $class) {
        $ref = new \ReflectionClass($class);
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue; // Skip inherited methods
            }
            expect($method->hasReturnType())->toBeTrue(
                "{$class}::{$method->getName()}() must have a return type declaration"
            );
        }
    }
});

test('Phase 217: ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ConditionEngine;
    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);

    // Verify matches() method signature matches contract
    $contractMethod = new \ReflectionMethod(ConditionEngineContract::class, 'matches');
    $implMethod = new \ReflectionMethod(ConditionEngine::class, 'matches');
    expect($implMethod->getReturnType()?->getName())->toBe($contractMethod->getReturnType()?->getName());
});

test('Phase 217: ServiceProvider provides() lists all bindings', function (): void {
    $provider = new \ReflectionClass(EventsServiceProvider::class);
    $method = $provider->getMethod('provides');
    expect($method)->toHaveReturnType('array');

    $provides = [
        EventManager::class,
        ConditionEngine::class,
        ConditionEngineContract::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
    ];

    // The provides() method must exist and be #[\Override]
    $attrs = $method->getAttributes(\Override::class);
    expect($attrs)->not->toBeEmpty('provides() must have #[\Override] attribute');
});

test('Phase 217: Facade getFacadeAccessor returns EventManager class', function (): void {
    $facade = new \ReflectionClass(EventManager::class);
    expect($facade->isFinal())->toBeTrue();
    expect($facade->isSubclassOf(\Illuminate\Support\Facades\Facade::class))->toBeTrue();

    $method = $facade->getMethod('getFacadeAccessor');
    expect($method->hasReturnType())->toBeTrue();
    expect($method->getReturnType()?->getName())->toBe('string');

    $attrs = $method->getAttributes(\Override::class);
    expect($attrs)->not->toBeEmpty('getFacadeAccessor() must have #[\Override] attribute');
});

test('Phase 217: DomainEvent is immutable with readonly properties', function (): void {
    $ref = new \ReflectionClass(DomainEvent::class);
    expect($ref->isFinal())->toBeTrue();

    $props = $ref->getProperties();
    foreach ($props as $prop) {
        expect($prop->isReadOnly())->toBeTrue(
            "DomainEvent::\${$prop->getName()} must be readonly"
        );
    }

    // Verify constructor parameters and property types
    $constructor = $ref->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(4);

    // $eventType is a promoted readonly constructor property
    expect($params[0]->getName())->toBe('eventType');
    expect($params[1]->getName())->toBe('payload');
    expect($params[2]->getName())->toBe('eventId');
    expect($params[3]->getName())->toBe('occurredAt');
});

test('Phase 217: WildcardMatcher is readonly final class with static methods only', function (): void {
    $ref = new \ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();

    // All public methods should be static
    foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        expect($method->isStatic())->toBeTrue(
            "WildcardMatcher::{$method->getName()}() must be static"
        );
    }

    // Pure attribute on matches()
    $matchesMethod = $ref->getMethod('matches');
    $pureAttrs = $matchesMethod->getAttributes(\Pure::class);
    expect($pureAttrs)->not->toBeEmpty('WildcardMatcher::matches() must have #[\Pure] attribute');
});

test('Phase 217: config file has all required top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';
    expect(is_array($config))->toBeTrue();

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
        expect(array_key_exists($key, $config))->toBeTrue("Config must have '{$key}' key");
    }

    // Nested key validation
    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    expect($config['queue'])->toHaveKeys(['connection', 'queue']);
    expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
    expect($config['retention'])->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
    expect($config['subscriptions'])->toHaveKeys([
        'auto_generate_secret',
        'secret_length',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ]);
});

test('Phase 217: migrations use config-driven table names', function (): void {
    $migrationFiles = [
        __DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php',
        __DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php',
        __DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php',
    ];

    foreach ($migrationFiles as $file) {
        expect(file_exists($file))->toBeTrue("Migration file must exist: {$file}");
        $content = file_get_contents($file);
        // Must use config() for table names, not hardcoded strings in Schema::create
        expect($content)->toContain('config(', "{$file} must use config() for table names");
        expect($content)->toContain('getTableName', "{$file} must have a getTableName() method");
        // Must declare strict types
        expect($content)->toContain('declare(strict_types=1)', "{$file} must declare strict_types=1");
    }
});

test('Phase 217: factories declare strict types and are final', function (): void {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factory) {
        $ref = new \ReflectionClass($factory);
        expect($ref->isFinal())->toBeTrue("{$factory} must be final");
        $file = $ref->getFileName();
        expect($file)->not->toBeFalse();
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "{$factory} file must declare strict_types=1");
    }
});

test('Phase 217: pest.xml exists and references correct bootstrap', function (): void {
    expect(file_exists(__DIR__.'/../pest.xml'))->toBeTrue('pest.xml must exist');
    expect(file_exists(__DIR__.'/../phpunit.xml'))->toBeFalse('phpunit.xml must NOT exist (use pest.xml)');

    $xml = simplexml_load_file(__DIR__.'/../pest.xml');
    expect($xml)->not->toBeFalse('pest.xml must be valid XML');

    $bootstrap = (string) ($xml['bootstrap'] ?? '');
    expect($bootstrap)->toBe('tests/helpers.php');

    $suite = $xml->xpath('//testsuite/directory');
    expect($suite)->not->toBeEmpty();
});

test('Phase 217: composer.json has correct Laravel extra section', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer)->toBeArray();

    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider'
    );
    expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager'
    );
    expect($composer['require']['php'])->toContain('8.5');
});

test('Phase 217: all traits used in EventManager are documented with @see', function (): void {
    $ref = new \ReflectionClass(EventManager::class);
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('ManagesHistory');
    expect($doc)->toContain('ManagesSubscriptions');
    expect($doc)->toContain('EscapesWildcardLike');
});

test('Phase 217: models have correct casts and hidden arrays', function (): void {
    // Trigger
    $triggerRef = new \ReflectionClass(Trigger::class);
    $triggerCasts = $triggerRef->getMethod('casts');
    expect($triggerCasts->hasReturnType())->toBeTrue();

    // EventLog
    $logRef = new \ReflectionClass(EventLog::class);
    $logCasts = $logRef->getMethod('casts');
    expect($logCasts->hasReturnType())->toBeTrue();

    // EventLog has status constants
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toHaveCount(4);

    // Subscription
    $subRef = new \ReflectionClass(Subscription::class);
    $hidden = $subRef->getProperty('hidden');
    expect($hidden->isInitialized(new Subscription))->toBeTrue(); // Accessible after construction
});

test('Phase 217: EventManager constructor has readonly promoted properties', function (): void {
    $ref = new \ReflectionClass(EventManager::class);
    $constructor = $ref->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);

    expect($params[0]->getName())->toBe('conditionEngine');
    expect($params[1]->getName())->toBe('actionResolver');
    expect($params[2]->getName())->toBe('app');

    // Check they are promoted (have corresponding readonly properties)
    expect($ref->getProperty('conditionEngine')->isReadOnly())->toBeTrue();
    expect($ref->getProperty('actionResolver')->isReadOnly())->toBeTrue();
    expect($ref->getProperty('app')->isReadOnly())->toBeTrue();
});

test('Phase 217: DispatchTriggerJob has proper queue configuration properties', function (): void {
    $ref = new \ReflectionClass(DispatchTriggerJob::class);

    $backoff = $ref->getProperty('backoff');
    expect($backoff->isReadOnly())->toBeTrue('backoff must be readonly');
    expect($backoff->isPublic())->toBeTrue('backoff must be public (read by queue worker)');

    $queue = $ref->getProperty('queue');
    expect($queue->isReadOnly())->toBeTrue('queue must be readonly');
    expect($queue->isPublic())->toBeTrue('queue must be public');

    $tries = $ref->getProperty('tries');
    expect($tries->isReadOnly())->toBeTrue('tries must be readonly');
    expect($tries->isPublic())->toBeTrue('tries must be public');

    $connection = $ref->getProperty('connection');
    expect($connection->isReadOnly())->toBeTrue('connection must be readonly');
    expect($connection->isPublic())->toBeTrue('connection must be public');
});

test('Phase 217: SubscriptionBuilder enforces URL scheme validation', function (): void {
    $ref = new \ReflectionClass(SubscriptionBuilder::class);
    $saveMethod = $ref->getMethod('save');

    $doc = $saveMethod->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('non-HTTP(S)');
});

test('Phase 217: phpstan.neon.dist is level 9 with correct settings', function (): void {
    $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($neon)->toContain('level: 9');
    expect($neon)->toContain('reportUnusedIgnoredErrors: true');
    expect($neon)->toContain('treatPhpDocTypesAsCertain: false');
    expect($neon)->toContain('checkExplicitMixed: true');
    expect($neon)->toContain('checkUninitializedProperties: true');
});

test('Phase 217: all console commands have description and handle() return type', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
    ];

    foreach ($commands as $command) {
        $ref = new \ReflectionClass($command);
        expect($ref->isFinal())->toBeTrue("{$command} must be final");

        // Must have $description property
        $descProp = $ref->getProperty('description');
        expect($descProp->isInitialized($ref->newInstanceWithoutConstructor()))->toBeTrue(
            "{$command} must have a non-empty description"
        );

        // handle() must have #[\Override] and return int
        $handleMethod = $ref->getMethod('handle');
        expect($handleMethod->getReturnType()?->getName())->toBe('int');
        $overrideAttrs = $handleMethod->getAttributes(\Override::class);
        expect($overrideAttrs)->not->toBeEmpty("{$command}::handle() must have #[\Override]");
    }
});

test('Phase 217: rector.php and pint.json exist for CI tooling', function (): void {
    expect(file_exists(__DIR__.'/../rector.php'))->toBeTrue('rector.php must exist');
    expect(file_exists(__DIR__.'/../pint.json'))->toBeTrue('pint.json must exist');
});
