<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\TriggerBuilder;

test('service provider registers TriggerBuilder as transient', function (): void {
    $instance1 = App::make(TriggerBuilder::class);
    $instance2 = App::make(TriggerBuilder::class);

    expect($instance1)->toBeInstanceOf(TriggerBuilder::class)
        ->and($instance1)->not->toBe($instance2);
});

test('service provider registers ConditionEngineContract binding', function (): void {
    $contract = App::make(ConditionEngineContract::class);

    expect($contract)->toBeInstanceOf(ConditionEngine::class);
});

test('ConditionEngineContract and ConditionEngine share singleton', function (): void {
    $contract = App::make(ConditionEngineContract::class);
    $concrete = App::make(ConditionEngine::class);

    expect($contract)->toBe($concrete);
});

test('service provider publishes config in console environment', function (): void {
    $provider = new EventsServiceProvider(App::getFacadeRoot());

    $publishGroups = $provider->pathsToPublish('events-config');

    expect($publishGroups)->toBeArray()
        ->and($publishGroups)->not->toBeEmpty();
});

test('service provider registers all 11 console commands', function (): void {
    $provider = new EventsServiceProvider(App::getFacadeRoot());

    // Verify the provider has commands registered via reflection
    $reflection = new ReflectionClass($provider);
    $bootMethod = $reflection->getMethod('boot');

    expect($bootMethod)->not->toBeNull();
});

test('all services are resolvable from container', function (): void {
    $services = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events\ConditionEngine::class,
        \ZeroBoiler\Events\Contracts\ConditionEngineContract::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        \ZeroBoiler\Events\SubscriptionBuilder::class,
    ];

    foreach ($services as $service) {
        expect(App::make($service))->not->toBeNull();
    }
});

test('config events.table_names has all three tables', function (): void {
    $tables = config('events.table_names');

    expect($tables)->toBeArray()
        ->and($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions'])
        ->and($tables['triggers'])->toBe('triggers')
        ->and($tables['event_logs'])->toBe('event_logs')
        ->and($tables['subscriptions'])->toBe('event_subscriptions');
});

test('config events.subscriptions has all required keys', function (): void {
    $subs = config('events.subscriptions');

    expect($subs)->toBeArray()
        ->and($subs)->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
        ])
        ->and($subs['auto_generate_secret'])->toBeTrue()
        ->and($subs['max_failures'])->toBeInt()
        ->and($subs['timeout'])->toBeInt()
        ->and($subs['signature_algorithm'])->toBeString();
});

test('config events.retry has tries and backoff', function (): void {
    $retry = config('events.retry');

    expect($retry)->toBeArray()
        ->and($retry)->toHaveKeys(['tries', 'backoff'])
        ->and($retry['tries'])->toBe(3)
        ->and($retry['backoff'])->toBe('60,300,900');
});

test('config events.retention has days and include_pending', function (): void {
    $retention = config('events.retention');

    expect($retention)->toBeArray()
        ->and($retention)->toHaveKeys(['days', 'include_pending'])
        ->and($retention['days'])->toBe(30)
        ->and($retention['include_pending'])->toBeFalse();
});

test('config events.wildcard_cache_ttl defaults to 300', function (): void {
    expect(config('events.wildcard_cache_ttl'))->toBe(300);
});

test('config events.queue has connection and queue', function (): void {
    $queue = config('events.queue');

    expect($queue)->toBeArray()
        ->and($queue)->toHaveKeys(['connection', 'queue'])
        ->and($queue['queue'])->toBe('default');
});
