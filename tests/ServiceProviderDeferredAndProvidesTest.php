<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

describe('EventsServiceProvider provides() completeness', function (): void {
    test('provides() returns all registered service classes', function (): void {
        $provider = new EventsServiceProvider(app());
        $provides = $provider->provides();

        // All singleton and transient bindings from register()
        expect($provides)->toContain(EventManager::class);
        expect($provides)->toContain(ConditionEngine::class);
        expect($provides)->toContain(ConditionEngineContract::class);
        expect($provides)->toContain(ActionResolver::class);
        expect($provides)->toContain(TriggerBuilder::class);
        expect($provides)->toContain(SubscriptionBuilder::class);
        expect($provides)->toContain(EventScheduler::class);
    });

    test('provides() returns exactly 7 entries', function (): void {
        $provider = new EventsServiceProvider(app());
        expect($provider->provides())->toHaveCount(7);
    });

    test('provides() entries are all non-empty strings', function (): void {
        $provider = new EventsServiceProvider(app());
        foreach ($provider->provides() as $provided) {
            expect($provided)->toBeString();
            expect($provided)->not->toBeEmpty();
        }
    });
});

describe('Service container bindings correctness', function (): void {
    test('ConditionEngineContract resolves to ConditionEngine instance', function (): void {
        $engine = app(ConditionEngineContract::class);
        expect($engine)->toBeInstanceOf(ConditionEngine::class);
    });

    test('ConditionEngine is a shared singleton', function (): void {
        $a = app(ConditionEngine::class);
        $b = app(ConditionEngine::class);
        expect($a)->toBe($b);
    });

    test('ActionResolver is a shared singleton', function (): void {
        $a = app(ActionResolver::class);
        $b = app(ActionResolver::class);
        expect($a)->toBe($b);
    });

    test('EventManager is a shared singleton', function (): void {
        $a = app(EventManager::class);
        $b = app(EventManager::class);
        expect($a)->toBe($b);
    });

    test('TriggerBuilder is transient (each resolution gets fresh instance)', function (): void {
        $a = app(TriggerBuilder::class);
        $b = app(TriggerBuilder::class);
        expect($a)->not->toBe($b);
    });

    test('SubscriptionBuilder is transient (each resolution gets fresh instance)', function (): void {
        $a = app(SubscriptionBuilder::class);
        $b = app(SubscriptionBuilder::class);
        expect($a)->not->toBe($b);
    });

    test('EventScheduler is a shared singleton', function (): void {
        $a = app(EventScheduler::class);
        $b = app(EventScheduler::class);
        expect($a)->toBe($b);
    });
});
