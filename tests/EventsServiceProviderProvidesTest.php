<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

describe('EventsServiceProvider provides()', function () {
    it('returns all 7 expected service bindings', function () {
        $provider = new EventsServiceProvider($this->app);

        $provides = $provider->provides();

        expect($provides)->toBeArray()
            ->toHaveCount(7)
            ->toContain(EventManager::class)
            ->toContain(ConditionEngine::class)
            ->toContain(ConditionEngineContract::class)
            ->toContain(ActionResolver::class)
            ->toContain(TriggerBuilder::class)
            ->toContain(SubscriptionBuilder::class)
            ->toContain(EventScheduler::class);
    });

    it('contains only string values', function () {
        $provider = new EventsServiceProvider($this->app);

        foreach ($provider->provides() as $service) {
            expect($service)->toBeString();
        }
    });

    it('has no duplicate entries', function () {
        $provider = new EventsServiceProvider($this->app);

        $provides = $provider->provides();
        $unique = array_unique($provides);

        expect(count($unique))->toBe(count($provides));
    });
});
