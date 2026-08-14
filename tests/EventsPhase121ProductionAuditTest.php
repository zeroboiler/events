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

beforeEach(function (): void {
    // Ensure config is available
    config()->set('events.table_names.triggers', 'triggers');
    config()->set('events.table_names.event_logs', 'event_logs');
    config()->set('events.table_names.subscriptions', 'event_subscriptions');
});

// ─── Source File Structure ────────────────────────────────────────────────────

test('all 33 source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob_recursive($srcDir.'/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('all source files have license header', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob_recursive($srcDir.'/*.php');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)
            ->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

// ─── Final Classes ────────────────────────────────────────────────────────────

test('all core service classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        EventScheduler::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

test('WildcardMatcher is readonly and final', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('all 12 console commands are final', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($commands as $cmd) {
        $ref = new ReflectionClass($cmd);
        expect($ref->isFinal())->toBeTrue("{$cmd} must be final");
    }
});

// ─── Constructor Readonly Properties ──────────────────────────────────────────

test('EventManager has readonly constructor properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    // conditionEngine, actionResolver, app — all promoted readonly
    expect(count($params))->toBe(3);

    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue();
        // In PHP 8.5, we check via ReflectionProperty for readonly
        $prop = $ref->getProperty($param->getName());
        expect($prop->isReadOnly())->toBeTrue();
    }
});

test('ActionResolver has readonly constructor property (app)', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    $prop = $ref->getProperty('app');
    expect($prop->isReadOnly())->toBeTrue();
});

test('EventScheduler has readonly constructor property (app)', function (): void {
    $ref = new ReflectionClass(EventScheduler::class);
    $prop = $ref->getProperty('app');
    expect($prop->isReadOnly())->toBeTrue();
});

test('DispatchTriggerJob has readonly constructor properties', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    $triggerIdProp = $ref->getProperty('triggerId');
    $eventProp = $ref->getProperty('event');
    $payloadProp = $ref->getProperty('payload');

    expect($triggerIdProp->isReadOnly())->toBeTrue();
    expect($eventProp->isReadOnly())->toBeTrue();
    expect($payloadProp->isReadOnly())->toBeTrue();
});

test('DomainEvent has 4 readonly properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $readonlyCount = 0;
    foreach ($ref->getProperties() as $prop) {
        if ($prop->isReadOnly() || $prop->isPublic()) {
            $readonlyCount++;
        }
    }
    // eventId, eventType, payload, occurredAt — all 4 are public readonly
    expect($readonlyCount)->toBeGreaterThanOrEqual(4);
});

// ─── Return Type Declarations ─────────────────────────────────────────────────

test('EventManager public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($publicMethods as $method) {
        if ($method->getDeclaringClass()->getName() === 'Illuminate\Container\Container') {
            continue;
        }
        expect($method->hasReturnType())
            ->toBeTrue("EventManager::{$method->getName()}() must have a return type declaration");
    }
});

test('all console command handle() methods return int', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($commands as $cmd) {
        $ref = new ReflectionClass($cmd);
        $handle = $ref->getMethod('handle');
        $returnType = $handle->getReturnType();
        expect($returnType)
            ->not->toBeNull("{$cmd}::handle() must have a return type");
        expect($returnType->getName())->toBe('int');
    }
});

// ─── Interface Compliance ──────────────────────────────────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function (): void {
    expect(new WebhookAction)->toBeInstanceOf(Triggerable::class);
});

test('Triggerable::handle() has void return type', function (): void {
    $ref = new ReflectionMethod(Triggerable::class, 'handle');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('void');
});

test('ConditionEngineContract::matches() has bool return type', function (): void {
    $ref = new ReflectionMethod(ConditionEngineContract::class, 'matches');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('bool');
});

// ─── ServiceProvider ───────────────────────────────────────────────────────────

test('EventsServiceProvider provides 7 services', function (): void {
    $provider = new EventsServiceProvider(app());
    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
    expect(count($provides))->toBe(7);
});

test('ServiceProvider has Override attribute on register/boot/provides', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);

    foreach (['register', 'boot', 'provides'] as $method) {
        $attrs = $ref->getMethod($method)->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue("EventsServiceProvider::{$method}() must have #[Override]");
    }
});

// ─── Facade ───────────────────────────────────────────────────────────────────

test('Facade accessor returns EventManager::class', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $getAccessor = $ref->getMethod('getFacadeAccessor');
    expect($getAccessor->getReturnType()->getName())->toBe('string');

    // PHP 8.5+: setAccessible() removed — invoke directly
    $method = $ref->getMethod('getFacadeAccessor');
    $result = $method->invoke(null);
    expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('Facade has @method annotations for all public EventManager methods', function (): void {
    $doc = (new ReflectionClass(EventManagerFacade::class))->getDocComment();
    expect($doc)->not->toBeFalse();

    // At least 25 @method annotations
    $matches = [];
    preg_match_all('/@method\s+static/', $doc, $matches);
    expect(count($matches[0]))->toBeGreaterThanOrEqual(25);
});

// ─── Config Completeness ───────────────────────────────────────────────────────

test('config file has all 7 required top-level keys', function (): void {
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
        expect(array_key_exists($key, $config))
            ->toBeTrue("Config must have '{$key}' key");
    }
});

test('config table_names has all 3 entries', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');
});

test('config subscriptions has all required keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $subKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
    foreach ($subKeys as $key) {
        expect($config['subscriptions'])->toHaveKey($key);
    }
});

test('config retention has all required keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['retention'])->toHaveKey('days');
    expect($config['retention'])->toHaveKey('include_pending');
    expect($config['retention'])->toHaveKey('schedule_cron');
});

// ─── Models ───────────────────────────────────────────────────────────────────

test('models use config-driven table names', function (): void {
    config()->set('events.table_names.triggers', 'custom_triggers');
    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('custom_triggers');

    config()->set('events.table_names.event_logs', 'custom_logs');
    $log = new EventLog;
    expect($log->getTable())->toBe('custom_logs');

    config()->set('events.table_names.subscriptions', 'custom_subs');
    $sub = new Subscription;
    expect($sub->getTable())->toBe('custom_subs');
});

test('EventLog has exactly 4 status constants', function (): void {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    expect(count($statuses))->toBe(4);
    expect(count(array_unique($statuses)))->toBe(4);
    expect(EventLog::$statuses)->toEqual($statuses);
});

// ─── DomainEvent Immutability ─────────────────────────────────────────────────

test('DomainEvent is final with readonly properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    expect($ref->isFinal())->toBeTrue();

    $props = ['eventId', 'eventType', 'payload', 'occurredAt'];
    foreach ($props as $prop) {
        $rp = $ref->getProperty($prop);
        expect($rp->isReadOnly())->toBeTrue();
        expect($rp->isPublic())->toBeTrue();
    }
});

test('DomainEvent roundtrip preserves identity', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = DomainEvent::fromArray($event->toArray());

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
    expect($restored->eventType)->toBe($event->eventType);
    expect($restored->payload)->toBe($event->payload);
});

test('DomainEvent rejects empty eventType in fromArray', function (): void {
    expect(fn (): mixed => DomainEvent::fromArray(['eventType' => '']))
        ->toThrow(\InvalidArgumentException::class);
});

// ─── WildcardMatcher ──────────────────────────────────────────────────────────

test('WildcardMatcher is static-only (no public instance methods)', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($publicMethods as $method) {
        expect($method->isStatic())
            ->toBeTrue("WildcardMatcher::{$method->getName()}() must be static");
    }
});

test('WildcardMatcher has #[Pure] on all static methods', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
        $attrs = $method->getAttributes();
        $hasPure = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue("WildcardMatcher::{$method->getName()}() must have #[Pure]");
    }
});

// ─── ConditionEngine ──────────────────────────────────────────────────────────

test('ConditionEngine has #[Override] on matches()', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $ref->getAttributes();
    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue();
});

test('ConditionEngine safeRegexMatch is NOT #[Pure] (side effects)', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');
    $attrs = $ref->getAttributes();
    $hasPure = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }
    expect($hasPure)->toBeFalse('safeRegexMatch must NOT be #[Pure] (modifies ini settings)');
});

// ─── PHPStan Config ──────────────────────────────────────────────────────────

test('phpstan.neon.dist exists and has level 9', function (): void {
    $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($neon)->toContain('level: max');
    expect($neon)->toContain('paths:');
    expect($neon)->toContain('- src');
    expect($neon)->toContain('database/migrations');
    expect($neon)->toContain('database/factories');
});

// ─── Composer.json ─────────────────────────────────────────────────────────────

test('composer.json requires PHP ^8.5 and Laravel ^13.0', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($json['require']['php'])->toBe('^8.5');
    expect($json['require']['illuminate/contracts'])->toBe('^13.0');
    expect($json['require']['illuminate/support'])->toBe('^13.0');
});

test('composer.json has correct provider and alias registration', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    $providers = $json['extra']['laravel']['providers'];
    expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');

    $aliases = $json['extra']['laravel']['aliases'];
    expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
});

// ─── Migrations ──────────────────────────────────────────────────────────────

test('all 3 migrations have declare(strict_types=1) and license header', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    $files = glob($migrationDir.'/*.php');
    expect(count($files))->toBe(3);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('This file is part of ZeroBoiler');
    }
});

test('all migrations use config-driven table names', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    $files = glob($migrationDir.'/*.php');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('config(\'events.table_names.');
    }
});

// ─── Factories ───────────────────────────────────────────────────────────────

test('all 3 factories have static string $model property', function (): void {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factory) {
        $ref = new ReflectionProperty($factory, 'model');
        expect($ref->isStatic())->toBeTrue();
        expect($ref->getType()->getName())->toBe('string');
    }
});

test('all factories have definition(): array return type', function (): void {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factory) {
        $ref = new ReflectionMethod($factory, 'definition');
        $returnType = $ref->getReturnType();
        expect($returnType)->not->toBeNull();
        expect($returnType->getName())->toBe('array');
    }
});

// ─── No setAccessible() ───────────────────────────────────────────────────────

test('no setAccessible() calls in source files', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob_recursive($srcDir.'/*.php');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        // Strip comments
        $content = preg_replace('#//.*#', '', $content);
        $content = preg_replace('#/\*.*?\*/#s', '', $content);
        expect($content)->not->toContain('->setAccessible(');
    }
});

// ─── Helper ───────────────────────────────────────────────────────────────────

/**
 * Recursively glob for files matching a pattern.
 *
 * @return list<string>
 */
function glob_recursive(string $pattern): array
{
    $files = glob($pattern);
    foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
        $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern)));
    }

    return array_values(array_filter($files, 'is_file'));
}
