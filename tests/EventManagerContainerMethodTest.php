<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\EventManager;

describe('EventManager::container()', function (): void {
    it('returns the injected container instance', function (): void {
        $container = new Container;
        $container->singleton(ConditionEngine::class);
        $container->singleton(ActionResolver::class, fn (Container $app): ActionResolver => new ActionResolver($app));

        $manager = new EventManager(
            $container->make(ConditionEngine::class),
            $container->make(ActionResolver::class),
            $container,
        );

        expect($manager->container())->toBe($container);
    });

    it('returns the same instance on repeated calls', function (): void {
        $container = new Container;
        $container->singleton(ConditionEngine::class);
        $container->singleton(ActionResolver::class, fn (Container $app): ActionResolver => new ActionResolver($app));

        $manager = new EventManager(
            $container->make(ConditionEngine::class),
            $container->make(ActionResolver::class),
            $container,
        );

        $first = $manager->container();
        $second = $manager->container();

        expect($first)->toBe($second);
 });

    it('container can be used to resolve registered services', function (): void {
        $container = new Container;
        $container->singleton(ConditionEngine::class);
        $container->singleton(ActionResolver::class, fn (Container $app): ActionResolver => new ActionResolver($app));
        $container->instance('test.service', 'resolved_value');

        $manager = new EventManager(
            $container->make(ConditionEngine::class),
            $container->make(ActionResolver::class),
            $container,
        );

        expect($manager->container()->make('test.service'))->toBe('resolved_value');
    });
});
