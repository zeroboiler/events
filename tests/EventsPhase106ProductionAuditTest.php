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
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Actions\WebhookAction;

/**
 * Phase 106 — Comprehensive production audit: PHP 8.5 syntax, strict types,
 * return type declarations, docblocks, typed properties, interface contracts,
 * facade method signatures, constructor DI, and code consistency.
 */

// ─── Strict Types Verification ───────────────────────────────────────────────

test('all source files declare strict types', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob_recursive($srcDir.'/*.php');
    expect($files)->not->toBeEmpty('Source directory must contain PHP files');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect(str_contains($content, 'declare(strict_types=1)'))
            ->toBeTrue(basename($file).' must declare strict_types=1');
    }
});

// ─── Final Class Verification ───────────────────────────────────────────────

test('all service classes are final', function (): void {
    $nonModelFinalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        EventScheduler::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        DomainEvent::class,
    ];

    foreach ($nonModelFinalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue($class.' must be final');
    }
});

// ─── Readonly Property Verification ─────────────────────────────────────────

test('EventManager constructor properties are readonly', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    expect(count($params))->toBe(3, 'EventManager constructor must have 3 parameters');

    foreach ($params as $param) {
        expect($param->isReadOnly())->toBeTrue(
            "EventManager::__construct(\${$param->getName()}) must be readonly"
        );
    }
});

test('ActionResolver container property is readonly', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    $ctor = $ref->getMethod('__construct');
    $param = $ctor->getParameters()[0];

    expect($param->isReadOnly())->toBeTrue('ActionResolver container must be readonly');
});

test('EventScheduler container property is readonly', function (): void {
    $ref = new ReflectionClass(EventScheduler::class);
    $ctor = $ref->getMethod('__construct');
    $param = $ctor->getParameters()[0];

    expect($param->isReadOnly())->toBeTrue('EventScheduler container must be readonly');
});

test('TriggerBuilder eventManager property is readonly', function (): void {
    $ref = new ReflectionClass(TriggerBuilder::class);
    $ctor = $ref->getMethod('__construct');
    $param = $ctor->getParameters()[0];

    expect($param->isReadOnly())->toBeTrue('TriggerBuilder eventManager must be readonly');
});

test('SubscriptionBuilder eventManager property is readonly', function (): void {
    $ref = new ReflectionClass(SubscriptionBuilder::class);
    $ctor = $ref->getMethod('__construct');
    $param = $ctor->getParameters()[0];

    expect($param->isReadOnly())->toBeTrue('SubscriptionBuilder eventManager must be readonly');
});

test('DispatchTriggerJob public properties are readonly', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    $ctor = $ref->getMethod('__construct');

    foreach ($ctor->getParameters() as $param) {
        expect($param->isReadOnly())->toBeTrue(
            "DispatchTriggerJob::__construct(\${$param->getName()}) must be readonly"
        );
    }
});

// ─── DomainEvent Readonly Properties ───────────────────────────────────────

test('DomainEvent has exactly 4 readonly properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

    $readonlyProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->isReadOnly());

    expect(count($readonlyProps))->toBe(4, 'DomainEvent must have 4 readonly properties');

    $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), $readonlyProps);
    expect($names)->toContain('eventType');
    expect($names)->toContain('payload');
    expect($names)->toContain('eventId');
    expect($names)->toContain('occurredAt');
});

// ─── Return Type Declarations ───────────────────────────────────────────────

test('EventManager all public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "EventManager::{$method->getName()}() must have a return type declaration"
        );
    }
});

test('ConditionEngine all public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "ConditionEngine::{$method->getName()}() must have a return type declaration"
        );
    }
});

test('WildcardMatcher all public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

    foreach ($methods as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "WildcardMatcher::{$method->getName()}() must have a return type declaration"
        );
    }
});

// ─── Typed Properties Verification ───────────────────────────────────────────

test('Trigger model has typed properties', function (): void {
    $ref = new ReflectionClass(Trigger::class);
    $props = ['keyType', 'incrementing', 'fillable', 'hidden'];

    foreach ($props as $propName) {
        $prop = $ref->getProperty($propName);
        $type = $prop->getType();
        expect($type)->not->toBeNull("Trigger::\${$propName} must have a type declaration");
    }
});

test('EventLog model has typed properties', function (): void {
    $ref = new ReflectionClass(EventLog::class);
    $props = ['keyType', 'incrementing', 'fillable', 'hidden'];

    foreach ($props as $propName) {
        $prop = $ref->getProperty($propName);
        $type = $prop->getType();
        expect($type)->not->toBeNull("EventLog::\${$propName} must have a type declaration");
    }
});

// ─── Contract Interface Verification ─────────────────────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ConditionEngine;
    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('ConditionEngineContract has matches method', function (): void {
    $ref = new ReflectionClass(ConditionEngineContract::class);
    expect($ref->hasMethod('matches'))->toBeTrue();
    expect($ref->getMethod('matches')->hasReturnType())->toBeTrue();
});

test('Triggerable interface has handle method', function (): void {
    $ref = new ReflectionClass(Triggerable::class);
    expect($ref->hasMethod('handle'))->toBeTrue();
    expect($ref->getMethod('handle')->hasReturnType())->toBeTrue();
});

// ─── Facade Verification ───────────────────────────────────────────────────

test('facade accessor returns EventManager class name', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $method = $ref->getMethod('getFacadeAccessor');
    expect($method->hasReturnType())->toBeTrue('getFacadeAccessor must have return type');
    expect($method->isPublic())->toBeTrue();
    expect($method->isStatic())->toBeTrue();
    expect($method->isFinal())->toBeFalse('getFacadeAccessor must not be final (Laravel Facade contract)');
});

test('facade has Override attribute on getFacadeAccessor', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $method = $ref->getMethod('getFacadeAccessor');
    $attrs = $method->getAttributes();

    $attrNames = array_map(fn (ReflectionAttribute $a): string => $a->getName(), $attrs);
    expect($attrNames)->toContain('Override');
});

test('facade is final', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    expect($ref->isFinal())->toBeTrue('Facade must be final');
});

// ─── ServiceProvider Verification ───────────────────────────────────────────

test('service provider has register, boot, and provides methods', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);

    expect($ref->hasMethod('register'))->toBeTrue();
    expect($ref->hasMethod('boot'))->toBeTrue();
    expect($ref->hasMethod('provides'))->toBeTrue();
});

test('service provider is final', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);
    expect($ref->isFinal())->toBeTrue('EventsServiceProvider must be final');
});

test('service provider provides returns non-empty array', function (): void {
    $provider = new EventsServiceProvider(app());
    $provides = $provider->provides();

    expect($provides)->toBeArray();
    expect($provides)->not->toBeEmpty();
    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
});

// ─── Trait Method Verification ──────────────────────────────────────────────

test('EscapesWildcardLike has wildcardToLike method', function (): void {
    $ref = new ReflectionClass(EscapesWildcardLike::class);
    expect($ref->hasMethod('wildcardToLike'))->toBeTrue();
    expect($ref->getMethod('wildcardToLike')->getReturnType())->not->toBeNull();
});

test('GetsWebhookTimeout has getWebhookTimeout method', function (): void {
    $ref = new ReflectionClass(GetsWebhookTimeout::class);
    expect($ref->hasMethod('getWebhookTimeout'))->toBeTrue();
    expect($ref->getMethod('getWebhookTimeout')->getReturnType())->not->toBeNull();
});

test('ManagesHistory has getEventHistory, getStats, purgeLogs, getStalePendingLogs, deactivateExceededSubscriptions', function (): void {
    $ref = new ReflectionClass(ManagesHistory::class);
    $methods = ['getEventHistory', 'getStats', 'purgeLogs', 'getStalePendingLogs', 'deactivateExceededSubscriptions'];

    foreach ($methods as $method) {
        expect($ref->hasMethod($method))->toBeTrue("ManagesHistory must have {$method}()");
        expect($ref->getMethod($method)->getReturnType())->not->toBeNull("{$method}() must have return type");
    }
});

test('ManagesSubscriptions has subscribe, unsubscribe, listSubscriptions, getSubscription, subscribeWebhook', function (): void {
    $ref = new ReflectionClass(ManagesSubscriptions::class);
    $methods = ['subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription', 'subscribeWebhook'];

    foreach ($methods as $method) {
        expect($ref->hasMethod($method))->toBeTrue("ManagesSubscriptions must have {$method}()");
        expect($ref->getMethod($method)->getReturnType())->not->toBeNull("{$method}() must have return type");
    }
});

// ─── Model Scopes Return Types ─────────────────────────────────────────────

test('Trigger model scopes have return type declarations', function (): void {
    $ref = new ReflectionClass(Trigger::class);
    $scopes = ['scopeEnabled', 'scopeAsync', 'scopeOrderByPriority'];

    foreach ($scopes as $scope) {
        expect($ref->hasMethod($scope))->toBeTrue("Trigger must have {$scope}()");
        expect($ref->getMethod($scope)->getReturnType())->not->toBeNull("{$scope}() must have return type");
    }
});

test('EventLog model scopes have return type declarations', function (): void {
    $ref = new ReflectionClass(EventLog::class);
    $scopes = ['scopeWithStatus', 'scopeFailed', 'scopePending', 'scopeCompleted', 'scopeStalePending'];

    foreach ($scopes as $scope) {
        expect($ref->hasMethod($scope))->toBeTrue("EventLog must have {$scope}()");
        expect($ref->getMethod($scope)->getReturnType())->not->toBeNull("{$scope}() must have return type");
    }
});

test('Subscription model scopes have return type declarations', function (): void {
    $ref = new ReflectionClass(Subscription::class);
    $scopes = ['scopeActive', 'scopeForEvent', 'scopeOrderByPriority', 'scopeExceededFailures'];

    foreach ($scopes as $scope) {
        expect($ref->hasMethod($scope))->toBeTrue("Subscription must have {$scope}()");
        expect($ref->getMethod($scope)->getReturnType())->not->toBeNull("{$scope}() must have return type");
    }
});

// ─── PHPStan Config Verification ────────────────────────────────────────────

test('phpstan config targets level 9', function (): void {
    $config = parse_ini_file(__DIR__.'/../phpstan.neon.dist', scanner_mode: INI_SCANNER_TYPED);

    expect($config['parameters']['level'])->toBe(9);
});

test('phpstan config has all strict checks enabled', function (): void {
    $neonContent = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect(str_contains($neonContent, 'checkGenericClassInNonGenericObjectType: true'))->toBeTrue();
    expect(str_contains($neonContent, 'checkUninitializedProperties: true'))->toBeTrue();
    expect(str_contains($neonContent, 'checkClassLikeNameCase: true'))->toBeTrue();
    expect(str_contains($neonContent, 'reportUnmatchedIgnoredErrors: true'))->toBeTrue();
    expect(str_contains($neonContent, 'treatPhpDocTypesAsCertain: false'))->toBeTrue();
});

// ─── Composer.json Verification ──────────────────────────────────────────────

test('composer.json has correct PHP requirement', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
});

test('composer.json has correct service provider', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $providers = $composer['extra']['laravel']['providers'];

    expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
});

test('composer.json has correct facade alias', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $aliases = $composer['extra']['laravel']['aliases'];

    expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
});

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($readme)->toContain('version-'.$composer['version']);
});

// ─── Migration Config-Driven Table Names ────────────────────────────────────

test('triggers migration uses config for table name', function (): void {
    $content = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');
    expect(str_contains($content, "config('events.table_names.triggers'"))->toBeTrue();
});

test('event_logs migration uses config for table name', function (): void {
    $content = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');
    expect(str_contains($content, "config('events.table_names.event_logs'"))->toBeTrue();
});

test('event_subscriptions migration uses config for table name', function (): void {
    $content = file_get_contents(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');
    expect(str_contains($content, "config('events.table_names.subscriptions'"))->toBeTrue();
});

// ─── Config Completeness ────────────────────────────────────────────────────

test('config has all subscription keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect(isset($config['subscriptions']['auto_generate_secret']))->toBeTrue();
    expect(isset($config['subscriptions']['max_failures']))->toBeTrue();
    expect(isset($config['subscriptions']['timeout']))->toBeTrue();
    expect(isset($config['subscriptions']['signature_algorithm']))->toBeTrue();
    expect(isset($config['subscriptions']['cleanup_cron']))->toBeTrue();
});

test('config has all retention keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect(isset($config['retention']['days']))->toBeTrue();
    expect(isset($config['retention']['include_pending']))->toBeTrue();
    expect(isset($config['retention']['schedule_cron']))->toBeTrue();
});

test('config has all queue keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect(isset($config['queue']['connection']))->toBeTrue();
    expect(isset($config['queue']['queue']))->toBeTrue();
});

test('config has all retry keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect(isset($config['retry']['tries']))->toBeTrue();
    expect(isset($config['retry']['backoff']))->toBeTrue();
});

// ─── EventLog Status Constants ─────────────────────────────────────────────

test('EventLog has 4 unique status constants', function (): void {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    expect(count($statuses))->toBe(4);
    expect(count(array_unique($statuses)))->toBe(4, 'All status constants must be unique');
    expect(count(EventLog::$statuses))->toBe(4);
});

// ─── Helper function (used by test) ──────────────────────────────────────────

/**
 * Recursively glob for PHP files.
 *
 * @return list<string>
 */
function glob_recursive(string $pattern, int $flags = 0): array
{
    $files = glob($pattern, $flags);

    if ($files === false) {
        return [];
    }

    foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
        $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
    }

    return $files;
}
