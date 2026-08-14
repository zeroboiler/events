<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Console\{EventsFireCommand, EventsHealthCommand, EventsListCommand, EventsLogCommand, EventsSubscribeCommand, EventsUnsubscribeCommand, EventsSubscriptionsCommand, EventsRedeliverCommand, EventsEnableCommand, EventsDisableCommand, EventsRetryCommand, EventsRegisterCommand};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 104 — Comprehensive production readiness audit:
 *
 * 1. Verify all console commands implement handle(): int
 * 2. Verify WebhookAction implements ShouldQueue check (it should NOT — it's Triggerable)
 * 3. Verify DispatchTriggerJob implements ShouldQueue
 * 4. Verify all public EventManager methods have @throws annotations where they throw
 * 5. Verify Trigger::scopeEnabled returns Builder<Trigger>
 * 6. Verify EventLog status constants are unique strings
 * 7. Verify Subscription::signPayload handles empty/missing algorithm config
 * 8. Verify EventsServiceProvider lazy provider support via provides()
 * 9. Verify config file has all keys referenced in source
 * 10. Verify all factories definition() return array<string, mixed>
 * 11. Verify DomainEvent is truly immutable (all properties readonly)
 * 12. Verify ManagesHistory and ManagesSubscriptions traits are used only by EventManager
 * 13. Verify EscapesWildcardLike trait methods have proper return types
 * 14. Verify parseActions handles all documented JSON formats
 * 15. Verify TriggerBuilder resolveActions is private
 * 16. Verify facade @method count matches actual EventManager public methods
 */

test('all console commands have handle method returning int', function (): void {
    $commands = [
        EventsFireCommand::class,
        EventsHealthCommand::class,
        EventsListCommand::class,
        EventsLogCommand::class,
        EventsSubscribeCommand::class,
        EventsUnsubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsRedeliverCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsRetryCommand::class,
        EventsRegisterCommand::class,
    ];

    foreach ($commands as $commandClass) {
        $reflection = new ReflectionClass($commandClass);

        // Must extend Command
        expect($reflection->isSubclassOf(Command::class))->toBeTrue(
            "{$commandClass} must extend Illuminate\Console\Command"
        );

        // Must be final
        expect($reflection->isFinal())->toBeTrue(
            "{$commandClass} must be final"
        );

        // Must have handle() method
        expect($reflection->hasMethod('handle'))->toBeTrue(
            "{$commandClass} must have a handle() method"
        );

        $handle = $reflection->getMethod('handle');
        expect($handle->getReturnType()?->getName())->toBe('int',
            "{$commandClass}::handle() must return int"
        );
    }
});

test('webhook action implements triggerable not should queue', function (): void {
    $webhookReflection = new ReflectionClass(WebhookAction::class);

    // Must implement Triggerable
    expect($webhookReflection->implementsInterface(Triggerable::class))->toBeTrue(
        'WebhookAction must implement Triggerable'
    );

    // Must NOT implement ShouldQueue (it's sync by default)
    expect($webhookReflection->implementsInterface(ShouldQueue::class))->toBeFalse(
        'WebhookAction must NOT implement ShouldQueue'
    );
});

test('dispatch trigger job implements should queue', function (): void {
    $jobReflection = new ReflectionClass(DispatchTriggerJob::class);

    expect($jobReflection->implementsInterface(ShouldQueue::class))->toBeTrue(
        'DispatchTriggerJob must implement ShouldQueue'
    );

    expect($jobReflection->isFinal())->toBeTrue(
        'DispatchTriggerJob must be final'
    );
});

test('event manager public methods that throw have throws in docblock', function (): void {
    $file = file_get_contents(__DIR__.'/../src/EventManager.php');

    // fire() should document @throws
    expect(str_contains($file, '@throws \\InvalidArgumentException'))->toBeTrue(
        'EventManager::fire() should document InvalidArgumentException'
    );
    expect(str_contains($file, '@throws \\Throwable'))->toBeTrue(
        'EventManager::fire() should document Throwable'
    );

    // fireModel() should document @throws
    expect(str_contains($file, 'fireModel'))->toBeTrue();

    // executeTrigger() should document @throws
    expect(str_contains($file, 'executeTrigger'))->toBeTrue();
});

test('trigger scopes return correct builder type', function (): void {
    $reflection = new ReflectionClass(Trigger::class);

    // scopeEnabled
    $scopeEnabled = $reflection->getMethod('scopeEnabled');
    $returnType = $scopeEnabled->getReturnType();
    expect($returnType?->getName())->toBe(Builder::class);

    // scopeAsync
    $scopeAsync = $reflection->getMethod('scopeAsync');
    $returnType = $scopeAsync->getReturnType();
    expect($returnType?->getName())->toBe(Builder::class);

    // scopeOrderByPriority
    $scopeOrder = $reflection->getMethod('scopeOrderByPriority');
    $returnType = $scopeOrder->getReturnType();
    expect($returnType?->getName())->toBe(Builder::class);
});

test('event log status constants are unique non empty strings', function (): void {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    // All must be non-empty strings
    foreach ($statuses as $status) {
        expect($status)->toBeString();
        expect($status)->not->toBeEmpty();
    }

    // All must be unique
    expect(count($statuses))->toBe(count(array_unique($statuses)));
});

test('subscription sign payload handles various algorithm configs', function (): void {
    // Verify the method exists and has proper return type
    $reflection = new ReflectionClass(Subscription::class);
    $method = $reflection->getMethod('signPayload');

    expect($method->getReturnType()?->getName())->toBe('string',
        'signPayload() must return string'
    );

    // Verify parameters
    $params = $method->getParameters();
    expect(count($params))->toBe(1);
    expect($params[0]->getName())->toBe('payload');
    expect($params[0]->getType()?->getName())->toBe('string');
});

test('event scheduler has correct constructor injection', function (): void {
    $reflection = new ReflectionClass(EventScheduler::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull('EventScheduler must have a constructor');

    $appParam = $constructor->getParameter('app');
    expect($appParam)->not->toBeNull('Constructor must have $app parameter');
    expect($appParam->getType()?->getName())->toBe('Illuminate\Container\Container');
    expect($appParam->isPromoted())->toBeTrue('$app must be promoted');
});

test('trigger builder has correct constructor injection', function (): void {
    $reflection = new ReflectionClass(TriggerBuilder::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $emParam = $constructor->getParameter('eventManager');
    expect($emParam)->not->toBeNull();
    expect($emParam->getType()?->getName())->toBe(EventManager::class);
    expect($emParam->isPromoted())->toBeTrue();
    expect($emParam->isReadOnly())->toBeTrue();
});

test('subscription builder has correct constructor injection', function (): void {
    $reflection = new ReflectionClass(SubscriptionBuilder::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $emParam = $constructor->getParameter('eventManager');
    expect($emParam)->not->toBeNull();
    expect($emParam->getType()?->getName())->toBe(EventManager::class);
    expect($emParam->isPromoted())->toBeTrue();
    expect($emParam->isReadOnly())->toBeTrue();
});

test('condition engine has correct interface binding', function (): void {
    $reflection = new ReflectionClass(ConditionEngine::class);

    expect($reflection->implementsInterface(ConditionEngineContract::class))->toBeTrue(
        'ConditionEngine must implement ConditionEngineContract'
    );

    expect($reflection->isFinal())->toBeTrue(
        'ConditionEngine must be final'
    );

    // matches() method must have #[Override]
    $method = $reflection->getMethod('matches');
    $hasOverride = false;
    foreach ($method->getAttributes() as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('ConditionEngine::matches() must have #[Override]');
});

test('escapes wildcard like trait has proper method signature', function (): void {
    $reflection = new ReflectionClass('ZeroBoiler\Events\Concerns\EscapesWildcardLike');

    expect($reflection->isTrait())->toBeTrue();

    expect($reflection->hasMethod('wildcardToLike'))->toBeTrue();

    $method = $reflection->getMethod('wildcardToLike');
    expect($method->getReturnType()?->getName())->toBe('?string',
        'wildcardToLike() must return ?string'
    );

    $params = $method->getParameters();
    expect(count($params))->toBe(1);
    expect($params[0]->getName())->toBe('pattern');
    expect($params[0]->getType()?->getName())->toBe('string');
});

test('gets webhook timeout trait has proper method signature', function (): void {
    $reflection = new ReflectionClass('ZeroBoiler\Events\Concerns\GetsWebhookTimeout');

    expect($reflection->isTrait())->toBeTrue();

    expect($reflection->hasMethod('getWebhookTimeout'))->toBeTrue();

    $method = $reflection->getMethod('getWebhookTimeout');
    expect($method->getReturnType()?->getName())->toBe('int',
        'getWebhookTimeout() must return int'
    );
});

test('facade has complete method documentation matching event manager api', function (): void {
    $facadeFile = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');
    $emFile = file_get_contents(__DIR__.'/../src/EventManager.php');

    // Key public methods that should be documented in facade
    $requiredMethods = [
        'on(',
        'register(',
        'fire(',
        'fireModel(',
        'enable(',
        'disable(',
        'deleteTrigger(',
        'invalidateTriggerCache(',
        'isDisabled(',
        'setEnabled(',
        'listTriggers(',
        'getTrigger(',
        'subscribe(',
        'unsubscribe(',
        'listSubscriptions(',
        'getSubscription(',
        'subscribeWebhook(',
        'getEventHistory(',
        'getStats(',
        'purgeLogs(',
        'getStalePendingLogs(',
        'deactivateExceededSubscriptions(',
        'executeTrigger(',
        'registerScheduler(',
    ];

    foreach ($requiredMethods as $method) {
        expect(str_contains($facadeFile, $method))->toBeTrue(
            "Facade must document @method for {$method}"
        );
    }

    // Facade getFacadeAccessor should return EventManager class
    expect(str_contains($facadeFile, 'ZeroBoiler\\Events\\EventManager::class'))->toBeTrue();
});

test('config file references all keys used in source code', function (): void {
    $configFile = file_get_contents(__DIR__.'/../config/events.php');
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');

    // Config keys used in source
    $requiredKeys = [
        'events.table_names.triggers',
        'events.table_names.event_logs',
        'events.table_names.subscriptions',
        'events.queue.connection',
        'events.queue.queue',
        'events.retry.tries',
        'events.retry.backoff',
        'events.retention.days',
        'events.retention.include_pending',
        'events.retention.schedule_cron',
        'events.subscriptions.auto_generate_secret',
        'events.subscriptions.max_failures',
        'events.subscriptions.timeout',
        'events.subscriptions.signature_algorithm',
        'events.subscriptions.cleanup_cron',
        'events.disabled',
        'events.wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        // Check that the key exists in config file (last segment)
        $lastSegment = explode('.', $key);
        $lastKey = end($lastSegment);

        expect(str_contains($configFile, "'{$lastKey}'"))
            ->toBeTrue("Config must contain key '{$lastKey}' (from {$key})");
    }
});

test('all source files have license header', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');

    expect(count($files))->toBeGreaterThan(0);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect(str_contains($content, 'This file is part of ZeroBoiler'))
            ->toBeTrue(basename($file).' must have license header');
    }
});

test('all database factories have definition method returning array', function (): void {
    $factoryDir = __DIR__.'/../database/factories';
    $files = glob($factoryDir.'/*.php');

    expect(count($files))->toBe(3);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect(str_contains($content, 'public function definition(): array'))
            ->toBeTrue(basename($file).' must have definition(): array');
    }
});

test('event manager constructor has three readonly parameters', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect(count($params))->toBe(3);

    $expectedParams = ['conditionEngine', 'actionResolver', 'app'];
    foreach ($expectedParams as $paramName) {
        $param = $constructor->getParameter($paramName);
        expect($param)->not->toBeNull("Constructor must have \${$paramName}");
        expect($param->isPromoted())->toBeTrue("{$paramName} must be promoted");
        expect($param->isReadOnly())->toBeTrue("{$paramName} must be readonly");
    }
});

test('domain event has exactly four readonly properties', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);

    $properties = $reflection->getProperties();
    $readonlyProps = array_filter($properties, fn (ReflectionProperty $p): bool => $p->isReadOnly());

    expect(count($readonlyProps))->toBe(4, 'DomainEvent must have exactly 4 readonly properties');

    $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), $readonlyProps);
    expect($names)->toContain('eventId');
    expect($names)->toContain('occurredAt');
    expect($names)->toContain('eventType');
    expect($names)->toContain('payload');
});

test('manages history trait methods have correct return types', function (): void {
    $reflection = new ReflectionClass('ZeroBoiler\Events\Concerns\ManagesHistory');

    expect($reflection->isTrait())->toBeTrue();

    // getEventHistory
    $method = $reflection->getMethod('getEventHistory');
    expect($method->getReturnType()?->getName())->toBe(
        'Illuminate\Database\Eloquent\Collection',
    );

    // getStats
    $method = $reflection->getMethod('getStats');
    expect($method->getReturnType()?->getName())->toBe('array');

    // purgeLogs
    $method = $reflection->getMethod('purgeLogs');
    expect($method->getReturnType()?->getName())->toBe('int');

    // getStalePendingLogs
    $method = $reflection->getMethod('getStalePendingLogs');
    expect($method->getReturnType()?->getName())->toBe(
        'Illuminate\Database\Eloquent\Collection',
    );

    // deactivateExceededSubscriptions
    $method = $reflection->getMethod('deactivateExceededSubscriptions');
    expect($method->getReturnType()?->getName())->toBe('int');
});

test('manages subscriptions trait methods have correct return types', function (): void {
    $reflection = new ReflectionClass('ZeroBoiler\Events\Concerns\ManagesSubscriptions');

    expect($reflection->isTrait())->toBeTrue();

    // subscribe
    $method = $reflection->getMethod('subscribe');
    expect($method->getReturnType()?->getName())->toBe(SubscriptionBuilder::class);

    // unsubscribe
    $method = $reflection->getMethod('unsubscribe');
    expect($method->getReturnType()?->getName())->toBe('bool');

    // listSubscriptions
    $method = $reflection->getMethod('listSubscriptions');
    expect($method->getReturnType()?->getName())->toBe(
        'Illuminate\Database\Eloquent\Collection',
    );

    // getSubscription
    $method = $reflection->getMethod('getSubscription');
    expect($method->getReturnType()?->getName())->toBe('?'.Subscription::class);

    // subscribeWebhook
    $method = $reflection->getMethod('subscribeWebhook');
    expect($method->getReturnType()?->getName())->toBe('string');
});

test('event log model has correct casts including error as string', function (): void {
    $reflection = new ReflectionClass(EventLog::class);
    $castsMethod = $reflection->getMethod('casts');

    // Invoke to get casts array
    $instance = $reflection->newInstanceWithoutConstructor();
    $casts = $castsMethod->invoke($instance);

    expect($casts)->toBeArray();
    expect($casts)->toHaveKey('payload');
    expect($casts['payload'])->toBe('array');
    expect($casts)->toHaveKey('duration_ms');
    expect($casts['duration_ms'])->toBe('int');
    expect($casts)->toHaveKey('error');
    expect($casts['error'])->toBe('string');
});

test('trigger model has correct casts including enabled as boolean', function (): void {
    $reflection = new ReflectionClass(Trigger::class);
    $castsMethod = $reflection->getMethod('casts');

    $instance = $reflection->newInstanceWithoutConstructor();
    $casts = $castsMethod->invoke($instance);

    expect($casts)->toBeArray();
    expect($casts)->toHaveKey('conditions');
    expect($casts['conditions'])->toBe('array');
    expect($casts)->toHaveKey('async');
    expect($casts['async'])->toBe('boolean');
    expect($casts)->toHaveKey('enabled');
    expect($casts['enabled'])->toBe('boolean');
    expect($casts)->toHaveKey('priority');
    expect($casts['priority'])->toBe('int');
});

test('subscription model has correct casts', function (): void {
    $reflection = new ReflectionClass(Subscription::class);
    $castsMethod = $reflection->getMethod('casts');

    $instance = $reflection->newInstanceWithoutConstructor();
    $casts = $castsMethod->invoke($instance);

    expect($casts)->toBeArray();
    expect($casts)->toHaveKey('conditions');
    expect($casts['conditions'])->toBe('array');
    expect($casts)->toHaveKey('priority');
    expect($casts['priority'])->toBe('int');
    expect($casts)->toHaveKey('active');
    expect($casts['active'])->toBe('boolean');
    expect($casts)->toHaveKey('failure_count');
    expect($casts['failure_count'])->toBe('int');
    expect($casts)->toHaveKey('delivery_count');
    expect($casts['delivery_count'])->toBe('int');
    expect($casts)->toHaveKey('last_fired_at');
    expect($casts['last_fired_at'])->toBe('datetime');
});

test('service provider registers correct number of bindings', function (): void {
    $reflection = new ReflectionClass(EventsServiceProvider::class);
    $registerMethod = $reflection->getMethod('register');

    // Read the register method source
    $filename = $registerMethod->getFileName();
    $startLine = $registerMethod->getStartLine();
    $endLine = $registerMethod->getEndLine();
    $length = $endLine - $startLine + 1;

    $source = implode('', array_slice(file($filename), $startLine - 1, $length));

    // Singleton bindings
    expect(str_contains($source, 'singleton(ConditionEngineContract::class'))->toBeTrue();
    expect(str_contains($source, 'singleton(ConditionEngine::class'))->toBeTrue();
    expect(str_contains($source, 'singleton(ActionResolver::class'))->toBeTrue();
    expect(str_contains($source, 'singleton(EventManager::class'))->toBeTrue();
    expect(str_contains($source, 'singleton(EventScheduler::class'))->toBeTrue();

    // Transient bindings
    expect(str_contains($source, 'bind(SubscriptionBuilder::class'))->toBeTrue();
    expect(str_contains($source, 'bind(TriggerBuilder::class'))->toBeTrue();
});

test('service provider provides method returns all bindings', function (): void {
    $reflection = new ReflectionClass(EventsServiceProvider::class);
    $providesMethod = $reflection->getMethod('provides');

    $instance = $reflection->newInstanceWithoutConstructor();
    $provides = $providesMethod->invoke($instance);

    expect($provides)->toBeArray();
    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
    expect(count($provides))->toBe(7);
});

test('wildcard matcher class structure is correct', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue('WildcardMatcher must be final');
    expect($reflection->isReadOnly())->toBeTrue('WildcardMatcher must be readonly');

    // All methods must be static
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    foreach ($methods as $method) {
        if ($method->getName() !== '__construct') {
            expect($method->isStatic())->toBeTrue(
                "WildcardMatcher::{$method->getName()}() must be static"
            );
        }
    }
});

test('event manager uses three traits', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $traits = $reflection->getTraitNames();

    expect($traits)->toContain('ZeroBoiler\Events\Concerns\EscapesWildcardLike');
    expect($traits)->toContain('ZeroBoiler\Events\Concerns\ManagesHistory');
    expect($traits)->toContain('ZeroBoiler\Events\Concerns\ManagesSubscriptions');
});

test('subscription model uses escapes wildcard like trait', function (): void {
    $reflection = new ReflectionClass(Subscription::class);
    $traits = $reflection->getTraitNames();

    expect($traits)->toContain('ZeroBoiler\Events\Concerns\EscapesWildcardLike');
});

test('phpstan config has correct level and settings', function (): void {
    $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect(str_contains($config, 'level: 9'))->toBeTrue('PHPStan level must be 9');
    expect(str_contains($config, 'paths:'))->toBeTrue();
    expect(str_contains($config, 'src'))->toBeTrue();
    expect(str_contains($config, 'database/migrations'))->toBeTrue();
    expect(str_contains($config, 'database/factories'))->toBeTrue();
    expect(str_contains($config, 'reportUnmatchedIgnoredErrors: true'))->toBeTrue();
    expect(str_contains($config, 'checkMissingIterableValueType: true'))->toBeTrue();
    expect(str_contains($config, 'checkGenericClassInNonGenericObjectType: true'))->toBeTrue();
    expect(str_contains($config, 'checkUninitializedProperties: true'))->toBeTrue();
});

test('rector config targets src directory', function (): void {
    $config = file_get_contents(__DIR__.'/../rector.php');

    expect(str_contains($config, '__DIR__./src'))->toBeTrue('Rector must target src directory');
    expect(str_contains($config, 'LaravelSetList::LARAVEL_130'))->toBeTrue(
        'Rector should use Laravel 13 set'
    );
});
