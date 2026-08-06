<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

test('service provider registers EventManager as singleton', function (): void {
    $instance1 = App::make(EventManager::class);
    $instance2 = App::make(EventManager::class);

    expect($instance1)->toBeInstanceOf(EventManager::class)
        ->and($instance1)->toBe($instance2);
});

test('service provider registers ConditionEngine as singleton', function (): void {
    $instance1 = App::make(ConditionEngine::class);
    $instance2 = App::make(ConditionEngine::class);

    expect($instance1)->toBeInstanceOf(ConditionEngine::class)
        ->and($instance1)->toBe($instance2);
});

test('service provider registers ActionResolver as singleton', function (): void {
    $instance1 = App::make(ActionResolver::class);
    $instance2 = App::make(ActionResolver::class);

    expect($instance1)->toBeInstanceOf(ActionResolver::class)
        ->and($instance1)->toBe($instance2);
});

test('service provider registers SubscriptionBuilder as transient', function (): void {
    $instance1 = App::make(SubscriptionBuilder::class);
    $instance2 = App::make(SubscriptionBuilder::class);

    expect($instance1)->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($instance1)->not->toBe($instance2);
});

test('service provider registers TriggerBuilder in container', function (): void {
    $builder = App::make(TriggerBuilder::class);

    expect($builder)->toBeInstanceOf(TriggerBuilder::class);
});

test('service provider merges events config', function (): void {
    $config = config('events');

    expect($config)->not->toBeNull()
        ->and($config)->toBeArray()
        ->and($config)->toHaveKey('table_names')
        ->and($config)->toHaveKey('queue')
        ->and($config)->toHaveKey('retry')
        ->and($config)->toHaveKey('retention')
        ->and($config)->toHaveKey('subscriptions');
});

test('Event manager receives correct dependencies via container', function (): void {
    $manager = App::make(EventManager::class);

    // Access conditionEngine and actionResolver via reflection
    $reflection = new ReflectionClass($manager);
    $conditionEngineProp = $reflection->getProperty('conditionEngine');
    $actionResolverProp = $reflection->getProperty('actionResolver');

    expect($conditionEngineProp->getValue($manager))->toBeInstanceOf(ConditionEngine::class)
        ->and($actionResolverProp->getValue($manager))->toBeInstanceOf(ActionResolver::class);
});
