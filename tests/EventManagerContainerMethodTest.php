<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use ZeroBoiler\Events\EventManager;

describe('EventManager::container() method', function (): void {
    test('container() returns the application container instance', function (): void {
        $manager = app(EventManager::class);
        $container = $manager->container();

        expect($container)->toBeInstanceOf(Container::class);
    });

    test('container() returns the same instance as the app singleton', function (): void {
        $manager = app(EventManager::class);
        $container = $manager->container();

        // The container returned should be the same as app()
        expect($container)->toBe(app());
    });

    test('container() allows resolving services from it', function (): void {
        $manager = app(EventManager::class);
        $container = $manager->container();

        // Resolve the EventManager itself through the returned container
        $resolved = $container->make(EventManager::class);

        expect($resolved)->toBe($manager);
    });
});
