<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use ReflectionClass;
use ReflectionMethod;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

/**
 * Verifies that every @method annotation in the Facade corresponds to a
 * real public method on the underlying EventManager class — catches stale
 * facade docs when the API surface changes.
 */
describe('Facade method coverage', function (): void {
    test('facade accessor resolves to EventManager', function (): void {
        $accessor = (new ReflectionClass(EventManagerFacade::class))
            ->getMethod('getFacadeAccessor')
            ->invoke(null);

        expect($accessor)->toBe(EventManager::class);
    });

    test('every facade @method has a corresponding public method on EventManager', function (): void {
        $facadeDoc = (new ReflectionClass(EventManagerFacade::class))->getDocComment();
        $managerMethods = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(EventManager::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        // Extract @method names from facade docblock
        preg_match_all('/@method\s+static\s+[\w|<>\[\]\\\\$\s,]+\s+(\w+)\(/', (string) $facadeDoc, $matches);
        $facadeMethods = $matches[1];

        expect($facadeMethods)->not->toBeEmpty();

        foreach ($facadeMethods as $method) {
            expect($managerMethods)->toContain($method);
        }
    });

    test('EventManager public methods match facade @method count', function (): void {
        $facadeDoc = (new ReflectionClass(EventManagerFacade::class))->getDocComment();
        preg_match_all('/@method\s+static\s+[\w|<>\[\]\\\\$\s,]+\s+(\w+)\(/', (string) $facadeDoc, $matches);
        $facadeMethods = array_unique($matches[1]);

        // Count public methods that should be exposed (exclude constructor, trait internals)
        $managerPublic = array_filter(
            array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                (new ReflectionClass(EventManager::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            ),
            fn (string $name): bool => $name !== '__construct',
        );

        // Every public method should have a facade @method (or at least the count should be close)
        // We verify facade methods are a subset of manager methods above
        // Here we verify manager methods don't have massive gaps
        $managerOnly = array_diff($managerPublic, $facadeMethods);
        // Allow internal methods that don't need facade exposure
        $allowedMissing = [
            'getConfig',           // Internal config accessor helper
            'getTriggerCacheTtl',  // Internal cache TTL helper
        ];

        $unexpectedMissing = array_diff($managerOnly, $allowedMissing);

        expect($unexpectedMissing)->toBeEmpty(
            'EventManager has public methods missing from Facade @method annotations: '.implode(', ', $unexpectedMissing),
        );
    });

    test('facade proxy returns correct instance type', function (): void {
        $resolved = EventManagerFacade::getFacadeRoot();

        expect($resolved)->toBeInstanceOf(EventManager::class);
    });
});
