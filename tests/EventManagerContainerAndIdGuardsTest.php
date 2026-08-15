<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use ZeroBoiler\Events\EventManager;

describe('EventManager container() method and ID guards', function (): void {
    test('container() returns the application container', function (): void {
        $app = app();
        $manager = $app->make(EventManager::class);

        expect($manager->container())->toBeInstanceOf(Container::class);
    });

    test('container() returns the same instance as the app', function (): void {
        $app = app();
        $manager = $app->make(EventManager::class);

        expect($manager->container())->toBe($app);
    });

    test('getTrigger returns null for empty string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->getTrigger(''))->toBeNull();
    });

    test('getTrigger returns null for "0" string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->getTrigger('0'))->toBeNull();
    });

    test('deleteTrigger returns false for empty string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->deleteTrigger(''))->toBeFalse();
    });

    test('deleteTrigger returns false for "0" string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->deleteTrigger('0'))->toBeFalse();
    });

    test('enable returns false for empty string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->enable(''))->toBeFalse();
    });

    test('enable returns false for "0" string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->enable('0'))->toBeFalse();
    });

    test('disable returns false for empty string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->disable(''))->toBeFalse();
    });

    test('disable returns false for "0" string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->disable('0'))->toBeFalse();
    });

    test('listTriggers skips filter for "0" event string', function (): void {
        $manager = app(EventManager::class);

        // "0" should not trigger a where clause (treated as empty-ish)
        $results = $manager->listTriggers('0');
        expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    });
});
