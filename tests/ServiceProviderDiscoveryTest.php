<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

describe('EventsServiceProvider discovery and binding verification', function (): void {
    it('is listed in composer.json extra.laravel.providers', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['extra']['laravel']['providers'])->toContain(
            'ZeroBoiler\\Events\\EventsServiceProvider'
        );
    });

    it('has EventManager alias in composer.json extra.laravel.aliases', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['extra']['laravel']['aliases']['EventManager'])
            ->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
    });

    it('requires illuminate/bus for Queueable trait', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['require'])->toHaveKey('illuminate/bus');
    });

    it('requires illuminate/console for Artisan commands', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['require'])->toHaveKey('illuminate/console');
    });

    it('provides() returns all registered services', function (): void {
        // Use reflection to call provides() without extending the final class
        $app = new Container;
        $provider = new EventsServiceProvider($app);
        $ref = new \ReflectionMethod(EventsServiceProvider::class, 'provides');
        $ref->setAccessible(true);
        /** @var list<string> $provides */
        $provides = $ref->invoke($provider);

        expect($provides)->toContain(EventManager::class);
        expect($provides)->toContain(ConditionEngine::class);
        expect($provides)->toContain(ConditionEngineContract::class);
        expect($provides)->toContain(ActionResolver::class);
        expect($provides)->toContain(TriggerBuilder::class);
        expect($provides)->toContain(SubscriptionBuilder::class);
        expect($provides)->toContain(EventScheduler::class);
    });

    it('declares PHP ^8.5 in composer.json', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['require']['php'])->toBe('^8.5');
    });

    it('requires ramsey/uuid for DomainEvent', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['require'])->toHaveKey('ramsey/uuid');
    });

    it('has all required illuminate packages', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $required = $composer['require'];

        expect($required)->toHaveKey('illuminate/contracts');
        expect($required)->toHaveKey('illuminate/support');
        expect($required)->toHaveKey('illuminate/database');
        expect($required)->toHaveKey('illuminate/queue');
        expect($required)->toHaveKey('illuminate/bus');
        expect($required)->toHaveKey('illuminate/cache');
        expect($required)->toHaveKey('illuminate/console');
        expect($required)->toHaveKey('illuminate/http');
    });
});
