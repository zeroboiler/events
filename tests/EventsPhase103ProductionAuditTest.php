<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\ConditionEngineContract as ConditionEngineContractInterface;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 103 — PHP 8.5 production readiness audit:
 *
 * 1. Verify zero setAccessible() calls in test files (PHP 8.5 removed this method)
 * 2. Verify #[\Override] on all overridden methods
 * 3. Verify #[\Pure] on all pure methods
 * 4. Verify readonly promoted constructor properties
 * 5. Verify final classes on all non-model/non-trait/non-interface classes
 * 6. Verify README PHP 8.5 compatibility section exists
 * 7. Verify composer.json version matches README badge
 */

test('no setaccessible calls remain in test files — php 8 5 compatibility', function (): void {
    $testDir = __DIR__;
    $files = glob($testDir.'/*.php');

    expect(count($files))->toBeGreaterThan(0);

    $violations = [];
    foreach ($files as $file) {
        $lines = explode("\n", file_get_contents($file));
        foreach ($lines as $lineNum => $line) {
            // Skip comment-only lines
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*')) {
                continue;
            }

            // Match any ->setAccessible( call
            if (preg_match('/->setAccessible\s*\(/', $line)) {
                $violations[] = basename($file).':'.($lineNum + 1).': '.$trimmed;
            }
        }
    }

    // Also check the Phase29 comment noting removal is present
    $phase29 = file_get_contents($testDir.'/EventsPhase29ProductionTest.php');
    expect(str_contains($phase29, 'setAccessible'))->toBeTrue('Phase29 should reference setAccessible in comment');

    expect($violations)->toBeEmpty(
        'Found setAccessible() calls in: '.implode(', ', $violations).' — setAccessible() was removed in PHP 8.5'
    );
});

test('override attribute present on service provider overridden methods', function (): void {
    $providerFile = __DIR__.'/../src/EventsServiceProvider.php';
    $content = file_get_contents($providerFile);

    // register(), boot(), provides() should have #[\Override]
    expect(str_contains($content, '#[\\Override]'))->toBeTrue();

    $reflection = new ReflectionClass(EventsServiceProvider::class);

    $registerMethod = $reflection->getMethod('register');
    $attributes = $registerMethod->getAttributes(\Attribute::class);
    $hasOverride = false;
    foreach ($attributes as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('register() must have #[Override] attribute');

    $bootMethod = $reflection->getMethod('boot');
    $attributes = $bootMethod->getAttributes();
    $hasOverride = false;
    foreach ($attributes as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('boot() must have #[Override] attribute');

    $providesMethod = $reflection->getMethod('provides');
    $attributes = $providesMethod->getAttributes();
    $hasOverride = false;
    foreach ($attributes as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('provides() must have #[Override] attribute');
});

test('override attribute present on facade getFacadeAccessor', function (): void {
    $reflection = new ReflectionClass(EventManagerFacade::class);
    $method = $reflection->getMethod('getFacadeAccessor');

    $attributes = $method->getAttributes();
    $hasOverride = false;
    foreach ($attributes as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('getFacadeAccessor() must have #[Override] attribute');
});

test('override attribute present on model overridden methods', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);

        // getTable() should have #[Override]
        $getTable = $reflection->getMethod('getTable');
        $hasOverride = false;
        foreach ($getTable->getAttributes() as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue($modelClass.'::getTable() must have #[Override]');

        // boot() should have #[Override]
        $boot = $reflection->getMethod('boot');
        $hasOverride = false;
        foreach ($boot->getAttributes() as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue($modelClass.'::boot() must have #[Override]');

        // newFactory() should have #[Override]
        $newFactory = $reflection->getMethod('newFactory');
        $hasOverride = false;
        foreach ($newFactory->getAttributes() as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue($modelClass.'::newFactory() must have #[Override]');

        // casts() should have #[Override]
        $casts = $reflection->getMethod('casts');
        $hasOverride = false;
        foreach ($casts->getAttributes() as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue($modelClass.'::casts() must have #[Override]');
    }
});

test('pure attribute present on condition engine pure methods', function (): void {
    $reflection = new ReflectionClass(ConditionEngine::class);

    $pureMethods = ['evaluateCondition', 'strictEquals', 'getNestedValue', 'contains', 'between'];

    foreach ($pureMethods as $methodName) {
        $method = $reflection->getMethod($methodName);
        $hasPure = false;
        foreach ($method->getAttributes() as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue("ConditionEngine::{$methodName}() must have #[Pure] attribute");
    }

    // safeRegexMatch should NOT have #[Pure] (it calls ini_set)
    $safeRegex = $reflection->getMethod('safeRegexMatch');
    $hasPure = false;
    foreach ($safeRegex->getAttributes() as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }
    expect($hasPure)->toBeFalse('safeRegexMatch() must NOT have #[Pure] (calls ini_set)');
});

test('pure attribute present on wildcard matcher methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    $pureMethods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($pureMethods as $methodName) {
        $method = $reflection->getMethod($methodName);
        $hasPure = false;
        foreach ($method->getAttributes() as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue("WildcardMatcher::{$methodName}() must have #[Pure] attribute");
    }
});

test('readonly promoted constructor properties on service classes', function (): void {
    $classesWithReadonly = [
        EventManager::class => ['conditionEngine', 'actionResolver', 'app'],
        ActionResolver::class => ['app'],
        EventScheduler::class => ['app'],
        TriggerBuilder::class => ['eventManager'],
        SubscriptionBuilder::class => ['eventManager'],
    ];

    foreach ($classesWithReadonly as $class => $properties) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull("{$class} must have a constructor");

        foreach ($properties as $prop) {
            $param = $constructor->getParameter($prop);
            expect($param)->not->toBeNull("{$class} constructor must have \${$prop} parameter");
            expect($param->isPromoted())->toBeTrue("{$class}::\${$prop} must be promoted");

            $propReflection = $reflection->getProperty($prop);
            expect($propReflection->isReadOnly())->toBeTrue("{$class}::\${$prop} must be readonly");
        }
    }
});

test('domain event has readonly properties', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);

    expect($reflection->getProperty('eventId')->isReadOnly())->toBeTrue();
    expect($reflection->getProperty('occurredAt')->isReadOnly())->toBeTrue();
    expect($reflection->getProperty('eventType')->isReadOnly())->toBeTrue();
    expect($reflection->getProperty('payload')->isReadOnly())->toBeTrue();
});

test('dispatch trigger job has readonly promoted properties', function (): void {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    foreach (['triggerId', 'event', 'payload'] as $prop) {
        $param = $constructor->getParameter($prop);
        expect($param)->not->toBeNull();
        expect($param->isPromoted())->toBeTrue("DispatchTriggerJob::\${$prop} must be promoted");

        $propReflection = $reflection->getProperty($prop);
        expect($propReflection->isReadOnly())->toBeTrue("DispatchTriggerJob::\${$prop} must be readonly");
    }
});

test('final classes on all non model non trait non interface classes', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
        EventsServiceProvider::class,
        EventManagerFacade::class,
        DomainEvent::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        WildcardMatcher::class,
    ];

    foreach ($finalClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} must be final");
    }
});

test('readme php 8.5 compatibility section exists', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect(str_contains($readme, 'PHP 8.5 Compatibility'))->toBeTrue(
        'README must have a PHP 8.5 Compatibility section'
    );
    expect(str_contains($readme, '#[\\Override]'))->toBeTrue(
        'README must document #[Override] attribute usage'
    );
    expect(str_contains($readme, '#[\\Pure]'))->toBeTrue(
        'README must document #[Pure] attribute usage'
    );
    expect(str_contains($readme, 'readonly'))->toBeTrue(
        'README must document readonly properties'
    );
    expect(str_contains($readme, 'setAccessible'))->toBeTrue(
        'README must mention setAccessible() removal'
    );
});

test('composer json version matches readme badge', function (): void {
    $composer = json_decode(
        file_get_contents(__DIR__.'/../composer.json'),
        true,
    );

    $composerVersion = $composer['version'];

    $readme = file_get_contents(__DIR__.'/../README.md');

    expect(str_contains($readme, "version-{$composerVersion}"))
        ->toBeTrue("README version badge must match composer.json version ({$composerVersion})");
});

test('all source files have declare strict types', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');

    expect(count($files))->toBeGreaterThan(0);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect(str_contains($content, 'declare(strict_types=1);'))
            ->toBeTrue(basename($file).' must have declare(strict_types=1)');
    }
});

test('all factory files have declare strict types and static model property', function (): void {
    $factoryDir = __DIR__.'/../database/factories';
    $files = glob($factoryDir.'/*.php');

    expect(count($files))->toBe(3); // TriggerFactory, EventLogFactory, SubscriptionFactory

    foreach ($files as $file) {
        $content = file_get_contents($file);

        expect(str_contains($content, 'declare(strict_types=1);'))
            ->toBeTrue(basename($file).' must have declare(strict_types=1)');

        // Verify static string $model property
        expect(str_contains($content, 'protected static string $model'))
            ->toBeTrue(basename($file).' must use protected static string $model');
    }
});
