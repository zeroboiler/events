<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use ZeroBoiler\Events\EventManager;

describe('EventManager::container() accessor', function () {
    it('returns the application container instance', function () {
        $manager = $this->app->make(EventManager::class);
        $container = $manager->container();
        expect($container)->toBeInstanceOf(Container::class);
    });

    it('returns the same container instance that was injected', function () {
        $manager = $this->app->make(EventManager::class);
        // The container should be the same object as the app itself
        expect($manager->container())->toBe($this->app);
    });

    it('allows resolving services from the returned container', function () {
        $manager = $this->app->make(EventManager::class);
        $container = $manager->container();

        // Should be able to resolve EventManager again
        $resolved = $container->make(EventManager::class);
        expect($resolved)->toBeInstanceOf(EventManager::class);
        // Should be the same singleton instance
        expect($resolved)->toBe($manager);
    });
});
