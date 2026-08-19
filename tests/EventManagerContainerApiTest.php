<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

describe('EventManager container() API', function () {
    it('returns the application container instance', function () {
        $manager = $this->app->make(EventManager::class);

        $container = $manager->container();

        expect($container)->toBeInstanceOf(Container::class);
    });

    it('returns the same container instance that was injected', function () {
        $manager = $this->app->make(EventManager::class);

        // The container returned by container() should be the app itself
        expect($manager->container())->toBe($this->app);
    });

    it('allows resolving services from the returned container', function () {
        $manager = $this->app->make(EventManager::class);
        $container = $manager->container();

        // Verify core services are resolvable through the container
        expect($container->make(EventManager::class))->toBeInstanceOf(EventManager::class);
        expect($container->make(ConditionEngine::class))->toBeInstanceOf(ConditionEngine::class);
        expect($container->make(ConditionEngineContract::class))->toBeInstanceOf(ConditionEngine::class);
        expect($container->make(ActionResolver::class))->toBeInstanceOf(ActionResolver::class);
        expect($container->make(EventScheduler::class))->beInstanceOf(EventScheduler::class);
    });

    it('returns a fresh TriggerBuilder on each resolution via container', function () {
        $manager = $this->app->make(EventManager::class);
        $container = $manager->container();

        $builder1 = $container->make(TriggerBuilder::class);
        $builder2 = $container->make(TriggerBuilder::class);

        // TriggerBuilder is bound as transient — each resolution is a new instance
        expect($builder1)->not->toBe($builder2);
    });

    it('returns a fresh SubscriptionBuilder on each resolution via container', function () {
        $manager = $this->app->make(EventManager::class);
        $container = $manager->container();

        $builder1 = $container->make(SubscriptionBuilder::class);
        $builder2 = $container->make(SubscriptionBuilder::class);

        // SubscriptionBuilder is bound as transient
        expect($builder1)->not->toBe($builder2);
    });

    it('returns the same EventManager singleton via container', function () {
        $manager = $this->app->make(EventManager::class);
        $container = $manager->container();

        $resolved1 = $container->make(EventManager::class);
        $resolved2 = $container->make(EventManager::class);

        // EventManager is a singleton
        expect($resolved1)->toBe($resolved2);
        expect($resolved1)->toBe($manager);
    });

    it('returns the same ConditionEngine singleton via container', function () {
        $manager = $this->app->make(EventManager::class);
        $container = $manager->container();

        $resolved1 = $container->make(ConditionEngine::class);
        $resolved2 = $container->make(ConditionEngine::class);

        // ConditionEngine is a singleton
        expect($resolved1)->toBe($resolved2);
    });

    it('provides access to the config repository via container', function () {
        $manager = $this->app->make(EventManager::class);
        $container = $manager->container();

        $config = $container->get('config');

        expect($config)->toBeInstanceOf(\Illuminate\Contracts\Config\Repository::class);
    });

    it('is accessible through the facade proxy', function () {
        // The facade resolves to the EventManager singleton
        $facadeRoot = EventManagerFacade::getFacadeRoot();

        expect($facadeRoot)->toBeInstanceOf(EventManager::class);
        expect($facadeRoot->container())->toBeInstanceOf(Container::class);
    });
});
