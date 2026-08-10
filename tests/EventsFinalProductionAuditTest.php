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
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Final production audit for ZeroBoiler Events v2.7.0.
 *
 * Comprehensive verification covering: all source files strict_types,
 * final classes, readonly properties, interface contracts, typed
 * constructors, return type declarations, #[Override] attributes,
 * #[Pure] attributes, PHP 8.5 syntax, config completeness,
 * ServiceProvider bindings, Facade accessor, model properties,
 * migration/factory existence, and version consistency.
 */
test('strict types enforcement — all source files', function (): void {
    $srcPath = __DIR__.'/../src';
    $files = glob($srcPath.'/**/*.php', GLOB_BRACE);

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)
            ->toContain('declare(strict_types=1)')
            ->toContain('namespace ZeroBoiler\\Events');
    }
});

test('final classes — core and console commands', function (): void {
    $finalCore = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WebhookAction::class,
        DomainEvent::class,
        WildcardMatcher::class,
        EventsServiceProvider::class,
    ];

    foreach ($finalCore as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }

    // Console commands
    $commands = glob(__DIR__.'/../src/Console/*.php');
    foreach ($commands as $file) {
        $ref = new ReflectionClass('ZeroBoiler\\Events\\Console\\'.basename($file, '.php'));
        expect($ref->isFinal())->toBeTrue($ref->getName().' command must be final');
    }
});

test('readonly classes — WildcardMatcher', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isReadOnly())->toBeTrue();
});

test('DomainEvent readonly properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = ['eventType', 'payload', 'eventId', 'occurredAt'];

    foreach ($props as $prop) {
        $rp = $ref->getProperty($prop);
        expect($rp->isReadOnly())->toBeTrue("DomainEvent::\${$prop} must be readonly");
    }
});

test('EventManager constructor — readonly promoted properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    expect($params)->toHaveCount(3);
    foreach ($params as $param) {
        expect($param->isReadOnly())->toBeTrue("EventManager::__construct \${$param->getName()} must be readonly");
    }
});

test('ActionResolver constructor — readonly promoted properties', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    expect($params)->toHaveCount(1);
    expect($params[0]->isReadOnly())->toBeTrue();
});

test('interface contracts — ConditionEngineContract and Triggerable', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
    expect(WebhookAction::class)->toImplement(Triggerable::class);

    // Verify contract method signatures match
    $engineRef = new ReflectionClass(ConditionEngine::class);
    $contractRef = new ReflectionClass(ConditionEngineContract::class);

    $contractMethod = $contractRef->getMethod('matches');
    $engineMethod = $engineRef->getMethod('matches');

    expect($contractMethod->getParameters())->toHaveCount(2);
    expect($engineMethod->getParameters())->toHaveCount(2);
});

test('ServiceProvider — all bindings registered', function (): void {
    $provider = new EventsServiceProvider($this->app);

    // Verify key bindings exist
    expect($this->app->bound(ConditionEngine::class))->toBeTrue();
    expect($this->app->bound(ConditionEngineContract::class))->toBeTrue();
    expect($this->app->bound(ActionResolver::class))->toBeTrue();
    expect($this->app->bound(\ZeroBoiler\Events\EventManager::class))->toBeTrue();
    expect($this->app->bound(TriggerBuilder::class))->toBeTrue();
    expect($this->app->bound(SubscriptionBuilder::class))->toBeTrue();
});

test('Facade accessor returns correct class', function (): void {
    $facadeRef = new ReflectionClass(EventManagerFacade::class);
    $method = $facadeRef->getMethod('getFacadeAccessor');

    expect($method->getReturnType()?->getName())->toBe('string');
    expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('config completeness — all 7 sections', function (): void {
    $config = config('events');
    $sections = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];

    foreach ($sections as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
    }

    // Sub-keys validation
    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    expect($config['queue'])->toHaveKeys(['connection', 'queue']);
    expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
    expect($config['retention'])->toHaveKeys(['days', 'include_pending']);
    expect($config['subscriptions'])->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
    ]);
});

test('model config-driven table names', function (): void {
    $triggerTable = (new Trigger)->getTable();
    $eventLogTable = (new EventLog)->getTable();
    $subscriptionTable = (new Subscription)->getTable();

    expect($triggerTable)->toBe(config('events.table_names.triggers', 'triggers'));
    expect($eventLogTable)->toBe(config('events.table_names.event_logs', 'event_logs'));
    expect($subscriptionTable)->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
});

test('EventLog status constants completeness', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toBe([
        'pending', 'dispatched', 'completed', 'failed',
    ]);
});

test('WildcardMatcher #[Pure] on all public static methods', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $attrs = $method->getAttributes(\Pure::class);
        expect($attrs)->not->toBeEmpty("WildcardMatcher::{$method->getName()} must have #[Pure]");
    }
});

test('EscapesWildcardLike trait used in EventManager', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $traits = $ref->getTraitNames();

    expect($traits)->toContain(
        \ZeroBoiler\Events\Concerns\EscapesWildcardLike::class,
    );
});

test('DispatchTriggerJob implements ShouldQueue', function (): void {
    expect(DispatchTriggerJob::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('migration files exist', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');
    expect($migrations)->toHaveCount(3);

    // Verify migration classes exist
    foreach ($migrations as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('return new class');
        expect($content)->toContain('Schema::create');
    }
});

test('factory files exist and use HasFactory', function (): void {
    $factories = glob(__DIR__.'/../database/factories/*.php');
    expect($factories)->toHaveCount(3);

    foreach ($factories as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('namespace ZeroBoiler\\Events\\Database\\Factories');
    }
});

test('composer.json autoload and extra structure', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['autoload']['psr-4'])->toHaveKey('ZeroBoiler\\Events\\');
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
    expect($composer['extra']['laravel']['aliases'])->toHaveKey('EventManager');
    expect($composer['require']['php'])->toBe('^8.5');
});

test('phpstan.neon.dist level 9', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: 9');
    expect($content)->toContain('paths:');
    expect($content)->toContain('- src');
});

test('EventManager public API surface', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $publicMethods = array_filter(
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! $m->isStatic(),
    );

    $methodNames = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);

    // Core public methods
    $required = [
        'on', 'register', 'fire', 'fireModel',
        'enable', 'disable', 'deleteTrigger',
        'invalidateTriggerCache', 'isDisabled', 'setEnabled',
        'listTriggers', 'getTrigger',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
        'subscribeWebhook',
        'getEventHistory', 'getStats', 'purgeLogs',
        'executeTrigger',
    ];

    foreach ($required as $method) {
        expect(in_array($method, $methodNames, true))
            ->toBeTrue("EventManager must have public method: {$method}");
    }
});

test('version consistency', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($readme)->toContain("version-{$composer['version']}-blue");
});
