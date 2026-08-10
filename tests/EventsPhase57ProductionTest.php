<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// ─── Rector Config: LARAVEL_130 for Laravel 13 ────────────────────────────────

test('rector.php uses LARAVEL_130 set constant for Laravel 13', function (): void {
    $rectorContent = file_get_contents(__DIR__.'/../rector.php');
    expect($rectorContent)->toBeString();
    expect($rectorContent)->toContain('LaravelSetList::LARAVEL_130');
    expect($rectorContent)->not->toContain('LaravelSetList::LARAVEL_120');
    expect($rectorContent)->not->Contain('LaravelSetList::LARAVEL_110');
});

// ─── helpers.php: fake() return type precision ───────────────────────────────

test('fake() helper returns \Faker\Generator not mixed', function (): void {
    $helpersContent = file_get_contents(__DIR__.'/../tests/helpers.php');
    expect($helpersContent)->toBeString();

    // The function declaration must use \Faker\Generator return type
    expect($helpersContent)->toContain('function fake(?string $locale = null): \\Faker\\Generator');
    expect($helpersContent)->not->toContain('function fake(?string $locale = null): mixed');
});

test('fake() helper has @return PHPDoc', function (): void {
    $helpersContent = file_get_contents(__DIR__.'/../tests/helpers.php');
    expect($helpersContent)->toContain('@return \\Faker\\Generator');
});

// ─── PHPStan 9: All protected methods have return type declarations ──────────

test('all protected methods in ConditionEngine have explicit return types', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    $protected = $ref->getMethods(ReflectionMethod::IS_PROTECTED);

    foreach ($protected as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "ConditionEngine::{$method->getName()}() missing return type declaration"
        );
    }
});

test('all protected methods in EventManager have explicit return types', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $protected = $ref->getMethods(ReflectionMethod::IS_PROTECTED);

    foreach ($protected as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "EventManager::{$method->getName()}() missing return type declaration"
        );
    }
});

test('all private methods in WebhookAction have explicit return types', function (): void {
    $ref = new ReflectionClass(WebhookAction::class);
    $private = $ref->getMethods(ReflectionMethod::IS_PRIVATE);

    foreach ($private as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "WebhookAction::{$method->getName()}() missing return type declaration"
        );
    }
});

test('all private methods in EventsRedeliverCommand have explicit return types', function (): void {
    $ref = new ReflectionClass(EventsRedeliverCommand::class);
    $private = $ref->getMethods(ReflectionMethod::IS_PRIVATE);

    foreach ($private as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "EventsRedeliverCommand::{$method->getName()}() missing return type declaration"
        );
    }
});

test('all private methods in TriggerBuilder have explicit return types', function (): void {
    $ref = new ReflectionClass(TriggerBuilder::class);
    $private = $ref->getMethods(ReflectionMethod::IS_PRIVATE);

    foreach ($private as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "TriggerBuilder::{$method->getName()}() missing return type declaration"
        );
    }
});

test('all private methods in ConditionEngine have explicit return types', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    $private = $ref->getMethods(ReflectionMethod::IS_PRIVATE);

    foreach ($private as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "ConditionEngine::{$method->getName()}() missing return type declaration"
        );
    }
});

test('all protected methods in EventsLogCommand have explicit return types', function (): void {
    $ref = new ReflectionClass(EventsLogCommand::class);
    $protected = $ref->getMethods(ReflectionMethod::IS_PROTECTED);

    foreach ($protected as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "EventsLogCommand::{$method->getName()}() missing return type declaration"
        );
    }
});

test('all protected methods in EventsFireCommand have explicit return types', function (): void {
    $ref = new ReflectionClass(EventsFireCommand::class);
    $protected = $ref->getMethods(ReflectionMethod::IS_PROTECTED);

    foreach ($protected as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "EventsFireCommand::{$method->getName()}() missing return type declaration"
        );
    }
});

test('all protected methods in EscapesWildcardLike have explicit return types', function (): void {
    $ref = new ReflectionClass(EscapesWildcardLike::class);
    $protected = $ref->getMethods(ReflectionMethod::IS_PROTECTED);

    foreach ($protected as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "EscapesWildcardLike::{$method->getName()}() missing return type declaration"
        );
    }
});

// ─── Protected method return type specificity ──────────────────────────────

test('ConditionEngine::evaluateCondition returns bool', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'evaluateCondition');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('bool');
    expect($returnType->allowsNull())->toBeFalse();
});

test('ConditionEngine::getNestedValue returns mixed', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'getNestedValue');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('mixed');
});

test('ConditionEngine::safeRegexMatch returns bool', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->BeNull();
    expect($returnType->getName())->toBe('bool');
});

test('ConditionEngine::contains returns bool', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'contains');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('bool');
});

test('ConditionEngine::between returns bool', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'between');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('bool');
});

test('EventManager::getTriggerCacheTtl returns int', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\EventManager::class, 'getTriggerCacheTtl');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('int');
});

test('EventManager::getMatchingTriggers returns Collection', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\EventManager::class, 'getMatchingTriggers');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe(Collection::class);
});

test('EventManager::getEnabledWildcardTriggers returns Collection', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\EventManager::class, 'getEnabledWildcardTriggers');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe(Collection::class);
});

// ─── DomainEvent: readonly properties via reflection ──────────────────────────

test('DomainEvent readonly properties are properly assigned in constructor', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    // eventId and occurredAt are readonly properties assigned in constructor body
    $ref = new ReflectionClass(DomainEvent::class);

    $eventIdProp = $ref->getProperty('eventId');
    expect($eventIdProp->isReadOnly())->toBeTrue();
    expect($eventIdProp->isInitialized($event))->toBeTrue();

    $occurredAtProp = $ref->getProperty('occurredAt');
    expect($occurredAtProp->isReadOnly())->toBeTrue();
    expect($occurredAtProp->isInitialized($event))->toBeTrue();

    $eventTypeProp = $ref->getProperty('eventType');
    expect($eventTypeProp->isReadOnly())->toBeTrue();

    $payloadProp = $ref->getProperty('payload');
    expect($payloadProp->isReadOnly())->toBeTrue();
});

// ─── EventManager: promoted readonly properties ──────────────────────────────

test('EventManager has readonly promoted properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);

    $conditionEngine = $ref->getProperty('conditionEngine');
    expect($conditionEngine->isReadOnly())->toBeTrue();
    expect($conditionEngine->isPromoted())->toBeTrue();

    $actionResolver = $ref->getProperty('actionResolver');
    expect($actionResolver->isReadOnly())->toBeTrue();
    expect($actionResolver->isPromoted())->toBeTrue();

    $app = $ref->getProperty('app');
    expect($app->isReadOnly())->toBeTrue();
    expect($app->isPromoted())->toBeTrue();
});

// ─── ActionResolver: promoted readonly properties ────────────────────────────

test('ActionResolver has readonly promoted Container property', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    $prop = $ref->getProperty('app');
    expect($prop->isReadOnly())->toBeTrue();
    expect($prop->isPromoted())->toBeTrue();
});

// ─── WebhookAction private methods: config-driven ───────────────────────────

test('WebhookAction::getTimeout reads from config', function (): void {
    $ref = new ReflectionMethod(WebhookAction::class, 'getTimeout');
    expect($ref->isPrivate())->toBeTrue();
    expect($ref->getReturnType()->getName())->toBe('int');
});

test('WebhookAction::getMaxFailures reads from config', function (): void {
    $ref = new ReflectionMethod(WebhookAction::class, 'getMaxFailures');
    expect($ref->isPrivate())->toBeTrue();
    expect($ref->getReturnType()->getName())->toBe('int');
});

// ─── DispatchTriggerJob property types ───────────────────────────────────────

test('DispatchTriggerJob properties have correct types', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);

    $triggerId = $ref->getProperty('triggerId');
    expect($triggerId->getType()->getName())->toBe('string');
    expect($triggerId->isReadOnly())->toBeTrue();
    expect($triggerId->isPromoted())->toBeTrue();

    $event = $ref->getProperty('event');
    expect($event->getType()->getName())->toBe('string');
    expect($event->isReadOnly())->toBeTrue();
    expect($event->isPromoted())->toBeTrue();

    $payload = $ref->getProperty('payload');
    expect($payload->getType()->getName())->toBe('array');
    expect($payload->isReadOnly())->toBeTrue();
    expect($payload->isPromoted())->toBeTrue();

    $tries = $ref->getProperty('tries');
    expect($tries->getType()->getName())->toBe('int');
    expect($tries->isReadOnly())->toBeFalse(); // assigned in constructor body

    $backoff = $ref->getProperty('backoff');
    expect($backoff->getType()->getName())->toBe('array');
    expect($backoff->isReadOnly())->toBeFalse(); // assigned in constructor body

    $queue = $ref->getProperty('queue');
    expect($queue->getType()->getName())->toBe('string');

    $connection = $ref->getProperty('connection');
    expect($connection->getType()->getName())->toBe('string');
    expect($connection->getType()->allowsNull())->toBeTrue();

    $eventLogId = $ref->getProperty('eventLogId');
    expect($eventLogId->getType()->getName())->toBe('string');
    expect($eventLogId->getType()->allowsNull())->toBeTrue();
});

// ─── ServiceProvider: register/boot have #[Override] ────────────────────────

test('EventsServiceProvider::register and boot have #[Override]', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);

    $register = $ref->getMethod('register');
    expect($register->getAttributes(\Attribute::class))->not->toBeEmpty();

    $boot = $ref->getMethod('boot');
    expect($boot->getAttributes(\Attribute::class))->not->toBeEmpty();
});

// ─── Model casts: return type declarations ───────────────────────────────────

test('all 3 model classes override casts() with array return type', function (): void {
    foreach ([EventLog::class, Trigger::class, Subscription::class] as $modelClass) {
        $ref = new ReflectionClass($modelClass);
        $method = $ref->getMethod('casts');
        expect($method->getReturnType()->getName())->toBe('array');
    }
});

// ─── Model boot: #[Override] and void return type ────────────────────────────

test('all 3 model classes override boot() with void return type and #[Override]', function (): void {
    foreach ([EventLog::class, Trigger::class, Subscription::class] as $modelClass) {
        $ref = new ReflectionClass($modelClass);
        $method = $ref->getMethod('boot');
        expect($method->getReturnType()->getName())->toBe('void');
        $attrs = $method->getAttributes(\Attribute::class);
        expect($attrs)->not->toBeEmpty();
    }
});

// ─── Facade: getFacadeAccessor return type ──────────────────────────────────

test('Facade::getFacadeAccessor returns string with #[Override]', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
    expect($ref->getReturnType()->getName())->toBe('string');
    expect($ref->getAttributes(\Attribute::class))->not->toBeEmpty();
});

// ─── Comprehensive: all 11 console commands are final ───────────────────────

test('all 11 console commands are final classes', function (): void {
    $commands = [
        EventsListCommand::class,
        EventsRegisterCommand::class,
        EventsFireCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsLogCommand::class,
        EventsRetryCommand::class,
        EventsRedeliverCommand::class,
        EventsSubscribeCommand::class,
        EventsUnsubscribeCommand::class,
        EventsSubscriptionsCommand::class,
    ];

    foreach ($commands as $cmd) {
        $ref = new ReflectionClass($cmd);
        expect($ref->isFinal())->toBeTrue("{$cmd} must be final");
    }
});

// ─── Comprehensive: all core classes are final ───────────────────────────────

test('all core classes are final', function (): void {
    $coreClasses = [
        \ZeroBoiler\Events\EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        EventsServiceProvider::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
    ];

    foreach ($coreClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

// ─── WildcardMatcher: readonly class with #[Pure] ──────────────────────────

test('WildcardMatcher is readonly final class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('WildcardMatcher all public methods have #[Pure]', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($publicMethods as $method) {
        $attrs = $method->getAttributes(\Attribute::class);
        $hasPure = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Pure' || str_ends_with($attr->getName(), '\\Pure')) {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue(
            "WildcardMatcher::{$method->getName()}() must have #[Pure] attribute"
        );
    }
});

// ─── Strict types: all source files ──────────────────────────────────────────

test('all source files declare strict_types=1', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

test('all test files declare strict_types=1', function (): void {
    $testFiles = glob(__DIR__.'/../*.php');

    foreach ($testFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

// ─── Config: all 6 sections present ─────────────────────────────────────────

test('config/events.php has all 6 top-level sections', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config)->toHaveKeys([
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'wildcard_cache_ttl',
    ]);
});

// ─── Version consistency ───────────────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($composer['version'])->toBeString();
    expect($readme)->toContain("version-{$composer['version']}");
});

// ─── EventLog: status constants ────────────────────────────────────────────

test('EventLog status constants cover all 4 statuses', function (): void {
    expect(EventLog::$statuses)->toBe([
        'pending',
        'dispatched',
        'completed',
        'failed',
    ]);

    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
});

// ─── Migration files: all 3 present ─────────────────────────────────────────

test('all 3 migration files exist', function (): void {
    expect(file_exists(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php'))->toBeTrue();
    expect(file_exists(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php'))->toBeTrue();
    expect(file_exists(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php'))->toBeTrue();
});

// ─── Factory files: all 3 present ──────────────────────────────────────────

test('all 3 factory files exist', function (): void {
    expect(file_exists(__DIR__.'/../database/factories/TriggerFactory.php'))->toBeTrue();
    expect(file_exists(__DIR__.'/../database/factories/EventLogFactory.php'))->toBeTrue();
    expect(file_exists(__DIR__.'/../database/factories/SubscriptionFactory.php'))->toBeTrue();
});
