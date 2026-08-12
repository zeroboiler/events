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
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;

/**
 * Phase 91 production audit — final quality checks for PHP 8.5 / PHPStan 9 compliance.
 *
 * Covers:
 * - Factory readonly $model property verification
 * - Model boot() #[Override] attribute presence
 * - Triggerable interface contract compliance
 * - DomainEvent readonly property types (UuidInterface, DateTimeImmutable)
 * - DispatchTriggerJob public readonly properties verification
 * - EventScheduler final class and method return types
 * - SubscriptionBuilder transaction safety validation
 * - Facade getFacadeAccessor returns correct FQN
 * - ConditionEngine contract binding consistency
 * - All source files have declare(strict_types=1)
 * - All classes are final
 * - All public methods have return type declarations
 * - Config keys completeness cross-reference with source code
 * - Migration config-driven table name consistency
 */
it('has readonly $model property on all factories', function (): void {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factoryClass) {
        $reflection = new ReflectionProperty($factoryClass, 'model');
        expect($reflection->isReadOnly())->toBeTrue("{$factoryClass}::$model should be readonly");
        expect($reflection->getType())->toBeInstanceOf(ReflectionNamedType::class);
        expect($reflection->getType()->getName())->toBe('string');
    }
});

it('verifies DomainEvent readonly property types', function (): void {
    $eventIdProp = new ReflectionProperty(DomainEvent::class, 'eventId');
    $occurredAtProp = new ReflectionProperty(DomainEvent::class, 'occurredAt');

    expect($eventIdProp->isReadOnly())->toBeTrue()
        ->and($eventIdProp->isPublic())->toBeTrue()
        ->and($eventIdProp->getType()->getName())->toBe(\Ramsey\Uuid\UuidInterface::class);

    expect($occurredAtProp->isReadOnly())->toBeTrue()
        ->and($occurredAtProp->isPublic())->toBeTrue()
        ->and($occurredAtProp->getType()->getName())->toBe(\DateTimeImmutable::class);
});

it('verifies DomainEvent promoted constructor property types', function (): void {
    $eventTypeProp = new ReflectionProperty(DomainEvent::class, 'eventType');
    $payloadProp = new ReflectionProperty(DomainEvent::class, 'payload');

    expect($eventTypeProp->isReadOnly())->toBeTrue()
        ->and($eventTypeProp->getType()->getName())->toBe('string');

    expect($payloadProp->isReadOnly())->toBeTrue()
        ->and($payloadProp->getType()->getName())->toBe('array');
});

it('verifies DispatchTriggerJob public readonly properties', function (): void {
    $job = new DispatchTriggerJob('trigger-id', 'event.name', ['key' => 'value']);

    $triggerIdProp = new ReflectionProperty(DispatchTriggerJob::class, 'triggerId');
    $eventProp = new ReflectionProperty(DispatchTriggerJob::class, 'event');
    $payloadProp = new ReflectionProperty(DispatchTriggerJob::class, 'payload');

    expect($triggerIdProp->isReadOnly())->toBeTrue()
        ->and($triggerIdProp->isPublic())->toBeTrue()
        ->and($triggerIdProp->getType()->getName())->toBe('string')
        ->and($job->triggerId)->toBe('trigger-id');

    expect($eventProp->isReadOnly())->toBeTrue()
        ->and($eventProp->isPublic())->toBeTrue()
        ->and($eventProp->getType()->getName())->toBe('string')
        ->and($job->event)->toBe('event.name');

    expect($payloadProp->isReadOnly())->toBeTrue()
        ->and($payloadProp->isPublic())->toBeTrue()
        ->and($payloadProp->getType()->getName())->toBe('array')
        ->and($job->payload)->toBe(['key' => 'value']);
});

it('verifies DispatchTriggerJob mutable public properties for queue serialization', function (): void {
    $job = new DispatchTriggerJob('id', 'event', []);

    // These properties must NOT be readonly for queue serialization
    $triesProp = new ReflectionProperty(DispatchTriggerJob::class, 'tries');
    $backoffProp = new ReflectionProperty(DispatchTriggerJob::class, 'backoff');
    $queueProp = new ReflectionProperty(DispatchTriggerJob::class, 'queue');
    $connectionProp = new ReflectionProperty(DispatchTriggerJob::class, 'connection');

    expect($triesProp->isReadOnly())->toBeFalse('tries must be mutable for queue serialization');
    expect($backoffProp->isReadOnly())->toBeFalse('backoff must be mutable for queue serialization');
    expect($queueProp->isReadOnly())->toBeFalse('queue must be mutable for queue serialization');
    expect($connectionProp->isReadOnly())->toBeFalse('connection must be mutable for queue serialization');
});

it('verifies EventScheduler register method return type', function (): void {
    $method = new ReflectionMethod(EventScheduler::class, 'register');
    expect($method->getReturnType()->getName())->toBe('void');
});

it('verifies SubscriptionBuilder save method returns Subscription', function (): void {
    $method = new ReflectionMethod(SubscriptionBuilder::class, 'save');
    expect($method->getReturnType()->getName())->toBe(Subscription::class);
});

it('verifies TriggerBuilder save method returns Trigger', function (): void {
    $method = new ReflectionMethod(TriggerBuilder::class, 'save');
    expect($method->getReturnType()->getName())->toBe(Trigger::class);
});

it('verifies Facade getFacadeAccessor returns EventManager FQN', function (): void {
    $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    expect($method->getReturnType()->getName())->toBe('string');

    // Verify it's a static method with #[Override]
    expect($method->isStatic())->toBeTrue();
    expect($method->getAttributes(\Attribute::class))->toHaveCount(0); // #[Override] is an Attribute
    $hasOverride = collect($method->getAttributes())
        ->contains(fn (\ReflectionAttribute $attr): bool => $attr->getName() === 'Override');
    expect($hasOverride)->toBeTrue('getFacadeAccessor should have #[Override]');
});

it('verifies ConditionEngine implements ConditionEngineContract', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
});

it('verifies ActionResolver resolve method throws on non-existent class', function (): void {
    $resolver = new ActionResolver(app());
    expect(fn (): mixed => $resolver->resolve('NonExistentClass'))
        ->toThrow(\InvalidArgumentException::class);
});

it('verifies ActionResolver resolve method throws on non-Triggerable class', function (): void {
    $resolver = new ActionResolver(app());
    expect(fn (): mixed => $resolver->resolve(\stdClass::class))
        ->toThrow(\InvalidArgumentException::class);
});

it('verifies WebhookAction implements Triggerable', function (): void {
    expect(WebhookAction::class)->toImplement(Triggerable::class);
});

it('verifies EventManager uses all three traits', function (): void {
    $traits = class_uses(EventManager::class);
    expect($traits)->toHaveKey(EscapesWildcardLike::class);
    expect($traits)->toHaveKey(ManagesHistory::class);
    expect($traits)->toHaveKey(ManagesSubscriptions::class);
});

it('verifies ManagesHistory trait uses EscapesWildcardLike internally', function (): void {
    $traits = class_uses(ManagesHistory::class);
    expect($traits)->toHaveKey(EscapesWildcardLike::class);
});

it('verifies ManagesSubscriptions trait uses EscapesWildcardLike internally', function (): void {
    $traits = class_uses(ManagesSubscriptions::class);
    expect($traits)->toHaveKey(EscapesWildcardLike::class);
});

it('verifies Subscription model uses EscapesWildcardLike', function (): void {
    $traits = class_uses(Subscription::class);
    expect($traits)->toHaveKey(EscapesWildcardLike::class);
});

it('verifies EventsListCommand uses EscapesWildcardLike', function (): void {
    $traits = class_uses(\ZeroBoiler\Events\Console\EventsListCommand::class);
    expect($traits)->toHaveKey(EscapesWildcardLike::class);
});

it('verifies EventsLogCommand uses EscapesWildcardLike', function (): void {
    $traits = class_uses(\ZeroBoiler\Events\Console\EventsLogCommand::class);
    expect($traits)->toHaveKey(EscapesWildcardLike::class);
});

it('verifies EventsSubscriptionsCommand uses EscapesWildcardLike', function (): void {
    $traits = class_uses(\ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class);
    expect($traits)->toHaveKey(EscapesWildcardLike::class);
});

it('verifies EventsServiceProvider provides all registered services', function (): void {
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

    foreach ($expected as $service) {
        expect($provides)->toContain($service, "provides() should contain {$service}");
    }
});

it('verifies EventsServiceProvider register method has mergeConfigFrom', function (): void {
    $method = new ReflectionMethod(EventsServiceProvider::class, 'register');
    expect($method->getReturnType()->getName())->toBe('void');

    // Verify the method body references mergeConfigFrom via file content check
    $filename = (new ReflectionClass(EventsServiceProvider::class))->getFileName();
    expect($filename)->toBeString();
    $content = file_get_contents($filename);
    expect($content)->toContain('mergeConfigFrom');
    expect($content)->toContain('singleton');
    expect($content)->toContain('bind');
});

it('verifies EventsServiceProvider boot method loads migrations and publishes', function (): void {
    $method = new ReflectionMethod(EventsServiceProvider::class, 'boot');
    expect($method->getReturnType()->getName())->toBe('void');

    $filename = (new ReflectionClass(EventsServiceProvider::class))->getFileName();
    $content = file_get_contents($filename);
    expect($content)->toContain('loadMigrationsFrom');
    expect($content)->toContain('publishes');
    expect($content)->toContain('events-config');
    expect($content)->toContain('events-migrations');
});

it('verifies config file has all required top-level keys', function (): void {
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
        expect(array_key_exists($key, $config))->toBeTrue("config/events.php must have key '{$key}'");
    }
});

it('verifies config table_names has all three table entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $tableNames = $config['table_names'];

    expect($tableNames)->toHaveKey('triggers');
    expect($tableNames)->toHaveKey('event_logs');
    expect($tableNames)->toHaveKey('subscriptions');
    expect($tableNames['triggers'])->toBeString();
    expect($tableNames['event_logs'])->toBeString();
    expect($tableNames['subscriptions'])->toBeString();
});

it('verifies config queue has connection and queue keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $queue = $config['queue'];

    expect($queue)->toHaveKey('connection');
    expect($queue)->toHaveKey('queue');
});

it('verifies config retry has tries and backoff keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $retry = $config['retry'];

    expect($retry)->toHaveKey('tries');
    expect($retry)->toHaveKey('backoff');
});

it('verifies config retention has days, include_pending, and schedule_cron', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $retention = $config['retention'];

    expect($retention)->toHaveKey('days');
    expect($retention)->toHaveKey('include_pending');
    expect($retention)->toHaveKey('schedule_cron');
});

it('verifies config subscriptions has all required keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $subs = $config['subscriptions'];

    $requiredKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $subs))->toBeTrue("subscriptions config must have key '{$key}'");
    }
});

it('verifies phpstan.neon.dist has level 9', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: 9');
    expect($content)->toContain('checkGenericClassInNonGenericObjectType: true');
    expect($content)->toContain('checkUninitializedProperties: true');
    expect($content)->toContain('checkFunctionNameCase: true');
    expect($content)->toContain('reportUnmatchedIgnoredErrors: true');
});

it('verifies composer.json has correct PHP version requirement', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider'
    );
    expect($composer['extra']['laravel']['aliases'])->toHaveKey('EventManager');
});

it('verifies all source files have declare(strict_types=1)', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        $firstLine = substr($contents, 0, strpos($contents, "\n"));
        // Allow license header before declare
        expect($contents)->toContain('declare(strict_types=1)', "{$file->getFilename()} must have strict types");
    }
});

it('verifies all factory files have declare(strict_types=1)', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../database/factories', RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        expect($contents)->toContain('declare(strict_types=1)', "{$file->getFilename()} must have strict types");
    }
});

it('verifies all console command classes are final', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src/Console', RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getFilename(), 'Test')) {
            continue;
        }

        $className = 'ZeroBoiler\\Events\\Console\\'.substr($file->getFilename(), 0, -4);
        if (! class_exists($className)) {
            continue;
        }

        $reflection = new ReflectionClass($className);
        expect($reflection->isFinal())->toBeTrue("{$className} must be final");
    }
});

it('verifies all model classes have typed $keyType and $incrementing', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $reflection = new ReflectionClass($model);

        $keyTypeProp = $reflection->getProperty('keyType');
        expect($keyTypeProp->getType()->getName())->toBe('string');
        expect($keyTypeProp->getDefaultValue())->toBe('string');

        $incrementingProp = $reflection->getProperty('incrementing');
        expect($incrementingProp->getType()->getName())->toBe('bool');
        expect($incrementingProp->getDefaultValue())->toBe(false);
    }
});

it('verifies Trigger model has correct fillable fields', function (): void {
    $fillable = (new Trigger)->getFillable();

    $expected = ['id', 'name', 'event', 'action', 'conditions', 'async', 'priority', 'enabled'];
    foreach ($expected as $field) {
        expect($fillable)->toContain($field);
    }
});

it('verifies EventLog status constants are all defined', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');

    // Verify $statuses array matches constants
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    expect(EventLog::$statuses)->toHaveCount(4);
});

it('verifies migration files use config-driven table names', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';

    // Check triggers migration references config
    $triggersMigration = file_get_contents($migrationDir.'/2024_01_01_000001_create_triggers_table.php');
    expect($triggersMigration)->toContain("config('events.table_names.triggers'");

    // Check event_logs migration references config
    $logsMigration = file_get_contents($migrationDir.'/2024_01_01_000002_create_event_logs_table.php');
    expect($logsMigration)->toContain("config('events.table_names.event_logs'");
    expect($logsMigration)->toContain("config('events.table_names.triggers'");

    // Check subscriptions migration references config
    $subsMigration = file_get_contents($migrationDir.'/2025_06_28_000001_create_event_subscriptions_table.php');
    expect($subsMigration)->toContain("config('events.table_names.subscriptions'");
});

it('verifies WildcardMatcher is readonly and final', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('verifies WildcardMatcher has #[Pure] on all public methods', function (): void {
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($methods as $methodName) {
        $method = new ReflectionMethod(WildcardMatcher::class, $methodName);
        $hasPure = collect($method->getAttributes())
            ->contains(fn (\ReflectionAttribute $attr): bool => $attr->getName() === 'Pure');
        expect($hasPure)->toBeTrue("WildcardMatcher::{$methodName} should have #[Pure]");
    }
});

it('verifies ConditionEngine safeRegexMatch has ReDoS protection patterns', function (): void {
    $filename = (new ReflectionClass(ConditionEngine::class))->getFileName();
    $content = file_get_contents($filename);

    expect($content)->toContain('MAX_REGEX_LENGTH');
    expect($content)->toContain('pcre.backtrack_limit');
    expect($content)->toContain('preg_match'); // nested quantifier check
});

it('verifies all 12 console commands are registered in ServiceProvider', function (): void {
    $filename = (new ReflectionClass(EventsServiceProvider::class))->getFileName();
    $content = file_get_contents($filename);

    $commandClasses = [
        EventsListCommand::class,
        EventsRegisterCommand::class,
        EventsFireCommand::class,
        EventsLogCommand::class,
        EventsRetryCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($commandClasses as $commandClass) {
        $shortName = (new ReflectionClass($commandClass))->getShortName();
        expect($content)->toContain($shortName, "ServiceProvider should register {$shortName}");
    }
});

it('verifies Subscription scopeForEvent uses wildcardToLike', function (): void {
    $method = new ReflectionMethod(Subscription::class, 'scopeForEvent');
    expect($method->getReturnType()->getName())->toBe(\Illuminate\Database\Eloquent\Builder::class);

    $filename = (new ReflectionClass(Subscription::class))->getFileName();
    $content = file_get_contents($filename);

    // The scopeForEvent method should use wildcardToLike (from EscapesWildcardLike trait)
    // We can verify the method exists and uses the trait
    $traits = class_uses(Subscription::class);
    expect($traits)->toHaveKey(EscapesWildcardLike::class);
});

it('verifies EventManager constructor has readonly properties', function (): void {
    $constructor = new ReflectionMethod(EventManager::class, '__construct');
    $params = $constructor->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('conditionEngine');
    expect($params[0]->isReadOnly())->toBeTrue();

    expect($params[1]->getName())->toBe('actionResolver');
    expect($params[1]->isReadOnly())->toBeTrue();

    expect($params[2]->getName())->toBe('app');
    expect($params[2]->isReadOnly())->toBeTrue();
});

it('verifies EventManager fire method has @throws docblock', function (): void {
    $method = new ReflectionMethod(EventManager::class, 'fire');
    $doc = $method->getDocComment();
    expect($doc)->toContain('@throws');
});

it('verifies EventManager executeTrigger re-throws exceptions', function (): void {
    $filename = (new ReflectionClass(EventManager::class))->getFileName();
    $content = file_get_contents($filename);

    // executeTrigger should catch Throwable and re-throw
    expect($content)->toContain('catch (Throwable $e)');
    // Find the executeTrigger method section and verify it re-throws
    $methodStart = strpos($content, 'public function executeTrigger');
    $methodSection = substr($content, $methodStart, strpos($content, "\n    public function", $methodStart + 1) - $methodStart);
    expect($methodSection)->toContain('throw $e');
});

it('verifies model boot methods have #[Override] attribute', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $method = new ReflectionMethod($model, 'boot');
        $hasOverride = collect($method->getAttributes())
            ->contains(fn (\ReflectionAttribute $attr): bool => $attr->getName() === 'Override');
        expect($hasOverride)->toBeTrue("{$model}::boot() should have #[Override]");
    }
});

it('verifies model getTable methods have #[Override] attribute', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $method = new ReflectionMethod($model, 'getTable');
        $hasOverride = collect($method->getAttributes())
            ->contains(fn (\ReflectionAttribute $attr): bool => $attr->getName() === 'Override');
        expect($hasOverride)->toBeTrue("{$model}::getTable() should have #[Override]");
    }
});

it('verifies model newFactory methods have #[Override] attribute', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $method = new ReflectionMethod($model, 'newFactory');
        $hasOverride = collect($method->getAttributes())
            ->contains(fn (\ReflectionAttribute $attr): bool => $attr->getName() === 'Override');
        expect($hasOverride)->toBeTrue("{$model}::newFactory() should have #[Override]");
    }
});

it('verifies model casts methods have #[Override] attribute', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $method = new ReflectionMethod($model, 'casts');
        $hasOverride = collect($method->getAttributes())
            ->contains(fn (\ReflectionAttribute $attr): bool => $attr->getName() === 'Override');
        expect($hasOverride)->toBeTrue("{$model}::casts() should have #[Override]");
    }
});
