<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

/**
 * Production readiness audit for PHPStan level 9 compliance.
 */
describe('PHPStan Level 9 Production Audit', function (): void {
    test('phpstan.neon.dist uses level 9 with comprehensive settings', function (): void {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($content)->not->toBeFalse();

        // Level 9 for maximum strictness
        expect($content)->toContain('level: 9');

        // Critical analysis flags
        expect($content)->toContain('reportUnusedIgnoredErrors: true');
        expect($content)->toContain('treatPhpDocTypesAsCertain: false');
        expect($content)->toContain('checkMissingIterableValueType: true');
        expect($content)->toContain('checkGenericClassInNonGenericObjectType: true');
        expect($content)->toContain('checkUninitializedProperties: true');
        expect($content)->toContain('checkFunctionNameCase: true');
        expect($content)->toContain('checkClassLikeNameCase: true');
        expect($content)->toContain('checkAlwaysTrueInstanceof: true');

        // Paths
        expect($content)->toContain('- src');
        expect($content)->toContain('- database/migrations');
        expect($content)->toContain('- database/factories');
        expect($content)->toContain('- tests');

        // Universal object crates for Laravel Eloquent
        expect($content)->toContain('universalObjectCratesClasses');
        expect($content)->toContain('Illuminate\\Database\\Eloquent\\Model');
    });

    test('phpstan.neon includes phpstan.neon.dist with level 9 override', function (): void {
        $content = file_get_contents(__DIR__.'/../phpstan.neon');
        expect($content)->toContain('includes:');
        expect($content)->toContain('phpstan.neon.dist');
        expect($content)->toContain('level: 9');
    });

    test('all 33 source files have declare(strict_types=1)', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $count = 0;
        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $count++;
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($count)->toBe(33, 'Expected exactly 33 source files');
        expect($violations)->toBeEmpty('Missing strict_types in: '.implode(', ', $violations));
    });

    test('all source files have license headers', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'This file is part of ZeroBoiler, licensed under the proprietary license.')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty('Missing license header in: '.implode(', ', $violations));
    });

    test('all service classes are final', function (): void {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            EventScheduler::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())->toBeTrue("{$class} must be final");
        }
    });

    test('WildcardMatcher is readonly final with static methods only', function (): void {
        $reflection = new ReflectionClass(WildcardMatcher::class);

        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            expect($method->isStatic())->toBeTrue(
                "WildcardMatcher::{$method->getName()} must be static"
            );
        }
    });

    test('all public methods have return type declarations', function (): void {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            EventScheduler::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            DomainEvent::class,
            WebhookAction::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                // Skip constructor
                if ($method->getName() === '__construct') {
                    continue;
                }
                $returnType = $method->getReturnType();
                expect($returnType)->not->toBeNull(
                    "{$class}::{$method->getName()} must have a return type declaration"
                );
            }
        }
    });

    test('EventManager uses readonly promoted constructor properties', function (): void {
        $reflection = new ReflectionClass(EventManager::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(3);

        foreach ($params as $param) {
            expect($param->isPromoted())->toBeTrue(
                "EventManager constructor parameter \${$param->getName()} must be promoted"
            );
            $reflectionParam = new ReflectionParameter([$EventManager::class, '__construct'], $param->getName());
            expect($reflectionParam->isReadOnly())->toBeTrue(
                "EventManager constructor parameter \${$param->getName()} must be readonly"
            );
        }
    });

    test('ServiceProvider registers 7 bindings and declares provides()', function (): void {
        $provider = new EventsServiceProvider(app());

        $provides = $provider->provides();

        expect($provides)->toContain(EventManager::class);
        expect($provides)->toContain(ConditionEngine::class);
        expect($provides)->toContain(ConditionEngineContract::class);
        expect($provides)->toContain(ActionResolver::class);
        expect($provides)->toContain(TriggerBuilder::class);
        expect($provides)->toContain(SubscriptionBuilder::class);
        expect($provides)->toContain(EventScheduler::class);
        expect($provides)->toHaveCount(7);
    });

    test('composer.json version matches README badge version', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $readme = file_get_contents(__DIR__.'/../README.md');

        expect($composer['version'])->toBe('5.16.0');
        expect($readme)->toContain('version-5.16.0');
    });

    test('no setAccessible calls in source files (removed in PHP 8.5)', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (str_contains($content, 'setAccessible(')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty('setAccessible found in: '.implode(', ', $violations));
    });

    test('config has all 7 required top-level keys', function (): void {
        $config = require __DIR__.'/../config/events.php';

        $requiredKeys = [
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'disabled',
            'wildcard_cache_ttl',
        ];

        foreach ($requiredKeys as $key) {
            expect($config)->toHaveKey($key);
        }
    });

    test('Facade has #[Override] on getFacadeAccessor', function (): void {
        $reflection = new ReflectionClass(EventManagerFacade::class);
        $method = $reflection->getMethod('getFacadeAccessor');

        $hasOverride = false;
        foreach ($method->getAttributes() as $attr) {
            if (str_contains($attr->getName(), 'Override')) {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue();
        expect($method->getReturnType()?->getName())->toBe('string');
        expect($method->isStatic())->toBeTrue();
    });
});
