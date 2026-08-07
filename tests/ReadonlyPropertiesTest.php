<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;

describe('Readonly promoted properties', function (): void {
    test('EventManager constructor parameters are #[Readonly] promoted', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(3);

        // Check that properties are promoted and readonly
        $props = $reflection->getProperties();
        $readonlyProps = array_filter($props, fn (\ReflectionProperty $p): bool =>
            $p->isReadOnly() && $p->isPromoted()
        );

        // conditionEngine, actionResolver, app should be readonly
        $readonlyNames = array_map(fn (\ReflectionProperty $p): string => $p->getName(), $readonlyProps);
        expect($readonlyNames)->toContain('conditionEngine')
            ->and($readonlyNames)->toContain('actionResolver')
            ->and($readonlyNames)->toContain('app');
    });

    test('ActionResolver has #[Readonly] container property', function (): void {
        $reflection = new ReflectionClass(ActionResolver::class);
        $props = $reflection->getProperties(\ReflectionProperty::IS_READONLY);
        $names = array_map(fn (\ReflectionProperty $p): string => $p->getName(), $props);

        expect($names)->toContain('app');
    });

    test('TriggerBuilder has #[Readonly] eventManager property', function (): void {
        $reflection = new ReflectionClass(TriggerBuilder::class);
        $props = $reflection->getProperties(\ReflectionProperty::IS_READONLY);
        $names = array_map(fn (\ReflectionProperty $p): string => $p->getName(), $props);

        expect($names)->toContain('eventManager');
    });

    test('SubscriptionBuilder has #[Readonly] eventManager property', function (): void {
        $reflection = new ReflectionClass(SubscriptionBuilder::class);
        $props = $reflection->getProperties(\ReflectionProperty::IS_READONLY);
        $names = array_map(fn (\ReflectionProperty $p): string => $p->getName(), $props);

        expect($names)->toContain('eventManager');
    });

    test('DispatchTriggerJob has #[Readonly] promoted properties', function (): void {
        $reflection = new ReflectionClass(DispatchTriggerJob::class);
        $readonlyProps = array_filter(
            $reflection->getProperties(),
            fn (\ReflectionProperty $p): bool => $p->isReadOnly() && $p->isPromoted(),
        );

        $names = array_map(fn (\ReflectionProperty $p): string => $p->getName(), $readonlyProps);
        expect($names)->toContain('triggerId')
            ->and($names)->toContain('event')
            ->and($names)->toContain('payload');
        // Note: $tries is NOT #[Readonly] — it's a declared class property
        // that gets overridden from config in the constructor body.
    });

    test('DispatchTriggerJob tries property is typed int and NOT readonly', function (): void {
        $reflection = new ReflectionClass(DispatchTriggerJob::class);
        $triesProp = $reflection->getProperty('tries');

        expect($triesProp->getType()->getName())->toBe('int')
            ->and($triesProp->isReadOnly())->toBeFalse()
            ->and($triesProp->isPromoted())->toBeFalse()
            ->and($triesProp->hasDefaultValue())->toBeTrue();
    });

    test('DispatchTriggerJob connection property is typed nullable string with null default', function (): void {
        $reflection = new ReflectionClass(DispatchTriggerJob::class);
        $connectionProp = $reflection->getProperty('connection');

        expect($connectionProp->getType()->getName())->toBe('string')
            ->and($connectionProp->getType()->allowsNull())->toBeTrue()
            ->and($connectionProp->isReadOnly())->toBeFalse()
            ->and($connectionProp->isPromoted())->toBeFalse()
            ->and($connectionProp->hasDefaultValue())->toBeTrue()
            ->and($connectionProp->getDefaultValue())->toBeNull();
    });

    test('DomainEvent has #[Readonly] eventId and occurredAt', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);
        $readonlyProps = array_filter(
            $reflection->getProperties(),
            fn (\ReflectionProperty $p): bool => $p->isReadOnly(),
        );

        $names = array_map(fn (\ReflectionProperty $p): string => $p->getName(), $readonlyProps);
        expect($names)->toContain('eventId')
            ->and($names)->toContain('occurredAt');
    });
});
