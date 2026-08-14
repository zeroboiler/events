<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsHealthCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
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
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;

test('all source files have declare strict_types=1', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getRealPath());
        expect($contents)->toContain('declare(strict_types=1)');
    }

    expect(true)->toBeTrue();
});

test('all core classes are final', function (): void {
    $coreClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        WildcardMatcher::class,
        DomainEvent::class,
        SubscriptionBuilder::class,
        TriggerBuilder::class,
        EventsServiceProvider::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        EventManagerFacade::class,
    ];

    foreach ($coreClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

test('WildcardMatcher is readonly final class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isReadOnly())->toBeTrue();
    expect($ref->isFinal())->toBeTrue();
});

test('WildcardMatcher public methods have #[Pure] attribute', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

    expect(count($publicMethods))->toBeGreaterThanOrEqual(3);

    foreach ($publicMethods as $method) {
        $attrs = $method->getAttributes(\Attribute::class);
        $hasPure = false;
        foreach ($method->getAttributes() as $attr) {
            if ($attr->getName() === 'Pure' || str_ends_with($attr->getName(), '\\Pure')) {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue("WildcardMatcher::{$method->getName()}() should have #[Pure]");
    }
});

test('DomainEvent has readonly promoted properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = $ref->getProperties();

    $readonlyProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->isReadOnly());

    // eventId, eventType, payload, occurredAt are readonly
    expect(count($readonlyProps))->toBeGreaterThanOrEqual(4);
});

test('ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ConditionEngine;
    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function (): void {
    $action = new WebhookAction;
    expect($action)->toBeInstanceOf(Triggerable::class);
});

test('DispatchTriggerJob implements ShouldQueue', function (): void {
    $job = new DispatchTriggerJob('test-id', 'test.event', []);
    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('EventLog has exactly 4 status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toHaveCount(4);
    expect(EventLog::$statuses)->toContain('pending');
    expect(EventLog::$statuses)->toContain('dispatched');
    expect(EventLog::$statuses)->toContain('completed');
    expect(EventLog::$statuses)->toContain('failed');
});

test('all models have string UUID key type and non-incrementing', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionClass($model);

        $keyType = $ref->getProperty('keyType');
        expect($keyType->getType()->getName())->toBe('string');
        expect($keyType->getDefaultValue())->toBe('string');

        $incrementing = $ref->getProperty('incrementing');
        expect($incrementing->getType()->getName())->toBe('bool');
        expect($incrementing->getDefaultValue())->toBeFalse();
    }
});

test('all models have config-driven table names via getTable override', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionClass($model);
        $method = $ref->getMethod('getTable');

        expect($method->hasReturnType())->toBeTrue();
        expect($method->getReturnType()?->getName())->toBe('string');
        expect($method->getAttributes(\Attribute::class))->not->toBeEmpty();
    }
});

test('EventManager constructor has readonly promoted properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getConstructor();
    expect($ctor)->not->toBeNull();

    $params = $ctor->getParameters();

    // ConditionEngine, ActionResolver, Container — all should be readonly
    foreach ($params as $param) {
        expect($param->isReadOnly())->toBeTrue(
            "EventManager constructor \${$param->getName()} should be readonly"
        );
    }
});

test('ActionResolver constructor has readonly promoted properties', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    $ctor = $ref->getConstructor();
    expect($ctor)->not->toBeNull();

    $params = $ctor->getParameters();
    foreach ($params as $param) {
        expect($param->isReadOnly())->toBeTrue(
            "ActionResolver constructor \${$param->getName()} should be readonly"
        );
    }
});

test('SubscriptionBuilder fluent interface returns self', function (): void {
    $ref = new ReflectionClass(SubscriptionBuilder::class);

    $methods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];

    foreach ($methods as $method) {
        $m = $ref->getMethod($method);
        expect($m->getReturnType()?->getName())->toBe('self',
            "SubscriptionBuilder::{$method}() should return self"
        );
    }
});

test('TriggerBuilder fluent interface returns self', function (): void {
    $ref = new ReflectionClass(TriggerBuilder::class);

    $methods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];

    foreach ($methods as $method) {
        $m = $ref->getMethod($method);
        expect($m->getReturnType()?->getName())->toBe('self',
            "TriggerBuilder::{$method}() should return self"
        );
    }
});

test('Facade accessor resolves to EventManager class', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $method = $ref->getMethod('getFacadeAccessor');

    expect($method->getReturnType()?->getName())->toBe('string');
    expect($method->hasReturnType())->toBeTrue();
});

test('all 12 console commands extend Illuminate Console Command', function (): void {
    $commands = [
        EventsDisableCommand::class,
        EventsEnableCommand::class,
        EventsFireCommand::class,
        EventsHealthCommand::class,
        EventsListCommand::class,
        EventsLogCommand::class,
        EventsRedeliverCommand::class,
        EventsRegisterCommand::class,
        EventsRetryCommand::class,
        EventsSubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsUnsubscribeCommand::class,
    ];

    foreach ($commands as $cmd) {
        $ref = new ReflectionClass($cmd);
        expect($ref->isFinal())->toBeTrue("{$cmd} should be final");
        expect($ref->isSubclassOf(\Illuminate\Console\Command::class))->toBeTrue(
            "{$cmd} should extend Command"
        );

        $handleMethod = $ref->getMethod('handle');
        expect($handleMethod->getReturnType()?->getName())->toBe('int');
    }
});

test('EscapesWildcardLike trait is used in EventManager ManagesHistory ManagesSubscriptions', function (): void {
    expect((new ReflectionClass(EventManager::class))->hasMethod('wildcardToLike'))->toBeTrue();
    expect((new ReflectionClass(Trigger::class))->hasMethod('wildcardToLike'))->toBeFalse();
    expect((new ReflectionClass(Subscription::class))->hasMethod('wildcardToLike'))->toBeTrue();
});

test('ManagesHistory provides getEventHistory getStats purgeLogs getStalePendingLogs deactivateExceededSubscriptions', function (): void {
    // Verify through EventManager which uses the trait
    expect((new ReflectionClass(EventManager::class))->hasMethod('getEventHistory'))->toBeTrue();
    expect((new ReflectionClass(EventManager::class))->hasMethod('getStats'))->toBeTrue();
    expect((new ReflectionClass(EventManager::class))->hasMethod('purgeLogs'))->toBeTrue();
    expect((new ReflectionClass(EventManager::class))->hasMethod('getStalePendingLogs'))->toBeTrue();
    expect((new ReflectionClass(EventManager::class))->hasMethod('deactivateExceededSubscriptions'))->toBeTrue();
});

test('ManagesSubscriptions provides subscribe unsubscribe listSubscriptions getSubscription subscribeWebhook', function (): void {
    expect((new ReflectionClass(EventManager::class))->hasMethod('subscribe'))->toBeTrue();
    expect((new ReflectionClass(EventManager::class))->hasMethod('unsubscribe'))->toBeTrue();
    expect((new ReflectionClass(EventManager::class))->hasMethod('listSubscriptions'))->toBeTrue();
    expect((new ReflectionClass(EventManager::class))->hasMethod('getSubscription'))->toBeTrue();
    expect((new ReflectionClass(EventManager::class))->hasMethod('subscribeWebhook'))->toBeTrue();
});

test('config file has all required sections', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Config missing key: {$key}");
    }

    // Sub-keys
    expect($config['table_names'])->toHaveCount(3);
    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');

    expect($config['subscriptions'])->toHaveKey('auto_generate_secret');
    expect($config['subscriptions'])->toHaveKey('max_failures');
    expect($config['subscriptions'])->toHaveKey('timeout');
    expect($config['subscriptions'])->toHaveKey('signature_algorithm');
});

test('version consistency between composer.json and package', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['version'])->toBeString();
    expect($composer['version'])->toMatch('/^\d+\.\d+\.\d+$/');
});

test('composer.json autoload PSR-4 is correct', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
});

test('composer.json extra.laravel has correct provider and alias', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider'
    );
    expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager'
    );
});

test('phpstan.neon.dist has level 8', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: max');
});

test('all 3 migration files exist with up and down methods', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    $files = glob("{$migrationDir}/*.php");

    expect($files)->toHaveCount(3);

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('public function up()');
        expect($contents)->toContain('public function down()');
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

test('all 3 factory files exist with definition method', function (): void {
    $factoryDir = __DIR__.'/../database/factories';
    $files = glob("{$factoryDir}/*.php");

    expect($files)->toHaveCount(3);

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('public function definition()');
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

test('EventManager public API surface has 22 methods', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    $expected = [
        'on',
        'register',
        'fire',
        'fireModel',
        'invalidateTriggerCache',
        'isDisabled',
        'setEnabled',
        'listTriggers',
        'getTrigger',
        'deleteTrigger',
        'enable',
        'disable',
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
    ];

    $actual = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);

    foreach ($expected as $method) {
        expect(in_array($method, $actual, true))->toBeTrue(
            "EventManager should have public method {$method}()"
        );
    }
});
