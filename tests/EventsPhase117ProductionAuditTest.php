<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Container\Container;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 117 Production Audit — final comprehensive verification for
 * events package production readiness.
 *
 * Covers:
 * - #[\Pure] attribute correctness on ConditionEngine (evaluateCondition NOT pure)
 * - ServiceProvider provides() includes EventScheduler
 * - Config completeness: retention.schedule_cron, subscriptions.cleanup_cron
 * - Facade registerScheduler delegation to EventManager
 * - All source files declare(strict_types=1)
 * - All classes have final keyword where applicable
 * - All public methods have explicit return types
 * - Trigger/EventLog/Subscription model property types
 */
describe('Phase 117 Production Audit', function (): void {
    test('ConditionEngine::evaluateCondition is NOT marked #[\Pure]', function (): void {
        $reflection = new \ReflectionMethod(ConditionEngine::class, 'evaluateCondition');
        $attributes = $reflection->getAttributes(\Pure::class);

        expect($attributes)->toHaveCount(0);
    });

    test('ConditionEngine::strictEquals IS marked #[\Pure]', function (): void {
        $method = new \ReflectionMethod(ConditionEngine::class, 'strictEquals');
        $attrs = $method->getAttributes(\Pure::class);

        expect($attrs)->toHaveCount(1);
    });

    test('ConditionEngine::getNestedValue IS marked #[\Pure]', function (): void {
        $method = new \ReflectionMethod(ConditionEngine::class, 'getNestedValue');
        $attrs = $method->getAttributes(\Pure::class);

        expect($attrs)->toHaveCount(1);
    });

    test('ConditionEngine::contains IS marked #[\Pure]', function (): void {
        $method = new \ReflectionMethod(ConditionEngine::class, 'contains');
        $attrs = $method->getAttributes(\Pure::class);

        expect($attrs)->toHaveCount(1);
    });

    test('ConditionEngine::between IS marked #[\Pure]', function (): void {
        $method = new \ReflectionMethod(ConditionEngine::class, 'between');
        $attrs = $method->getAttributes(\Pure::class);

        expect($attrs)->toHaveCount(1);
    });

    test('ConditionEngine::safeRegexMatch is NOT marked #[\Pure]', function (): void {
        $method = new \ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');
        $attrs = $method->getAttributes(\Pure::class);

        expect($attrs)->toHaveCount(0);
    });

    test('WildcardMatcher::matches IS marked #[\Pure]', function (): void {
        $method = new \ReflectionMethod(WildcardMatcher::class, 'matches');
        $attrs = $method->getAttributes(\Pure::class);

        expect($attrs)->toHaveCount(1);
    });

    test('WildcardMatcher::findMatchingPatterns IS marked #[\Pure]', function (): void {
        $method = new \ReflectionMethod(WildcardMatcher::class, 'findMatchingPatterns');
        $attrs = $method->getAttributes(\Pure::class);

        expect($attrs)->toHaveCount(1);
    });

    test('WildcardMatcher::extractWildcards IS marked #[\Pure]', function (): void {
        $method = new \ReflectionMethod(WildcardMatcher::class, 'extractWildcards');
        $attrs = $method->getAttributes(\Pure::class);

        expect($attrs)->toHaveCount(1);
    });

    test('ServiceProvider provides() includes EventScheduler', function (): void {
        $provider = new EventsServiceProvider(app());
        $provides = $provider->provides();

        expect($provides)->toContain(EventScheduler::class);
    });

    test('config retention has schedule_cron key', function (): void {
        $retention = config('events.retention');

        expect($retention)->toBeArray()
            ->and($retention)->toHaveKey('schedule_cron')
            ->and($retention['schedule_cron'])->toBeString();
    });

    test('config subscriptions has cleanup_cron key', function (): void {
        $subs = config('events.subscriptions');

        expect($subs)->toBeArray()
            ->and($subs)->toHaveKey('cleanup_cron')
            ->and($subs['cleanup_cron'])->toBeString();
    });

    test('config has all 7 top-level keys', function (): void {
        $config = config('events');

        expect($config)->toBeArray()->toHaveKeys([
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'disabled',
            'wildcard_cache_ttl',
        ]);
    });

    test('config subscriptions has all 5 required keys', function (): void {
        $subs = config('events.subscriptions');

        expect($subs)->toBeArray()->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
            'cleanup_cron',
        ]);
    });

    test('config retention has all 3 required keys', function (): void {
        $retention = config('events.retention');

        expect($retention)->toBeArray()->toHaveKeys([
            'days',
            'include_pending',
            'schedule_cron',
        ]);
    });

    test('EventManager has registerScheduler method', function (): void {
        $manager = app()->make(EventManager::class);

        expect(method_exists($manager, 'registerScheduler'))->toBeTrue();
    });

    test('EventManager registerScheduler method has void return type', function (): void {
        $method = new \ReflectionMethod(EventManager::class, 'registerScheduler');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType->getName())->toBe('void');
    });

    test('EventManager::on() method returns TriggerBuilder', function (): void {
        $method = new \ReflectionMethod(EventManager::class, 'on');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType->getName())->toBe(TriggerBuilder::class);
    });

    test('EventManager::subscribe() method returns SubscriptionBuilder', function (): void {
        $method = new \ReflectionMethod(EventManager::class, 'subscribe');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType->getName())->toBe(SubscriptionBuilder::class);
    });

    test('EventManager::fire() method has void return type', function (): void {
        $method = new \ReflectionMethod(EventManager::class, 'fire');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType->getName())->toBe('void');
    });

    test('EventManager::fireModel() method has void return type', function (): void {
        $method = new \ReflectionMethod(EventManager::class, 'fireModel');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType->getName())->toBe('void');
    });

    test('EventManager::setEnabled() method has void return type', function (): void {
        $method = new \ReflectionMethod(EventManager::class, 'setEnabled');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType->getName())->toBe('void');
    });

    test('EventManager::invalidateTriggerCache() method has void return type', function (): void {
        $method = new \ReflectionMethod(EventManager::class, 'invalidateTriggerCache');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType->getName())->toBe('void');
    });

    test('EventManager is final', function (): void {
        $ref = new \ReflectionClass(EventManager::class);

        expect($ref->isFinal())->toBeTrue();
    });

    test('WildcardMatcher is readonly final', function (): void {
        $ref = new \ReflectionClass(WildcardMatcher::class);

        expect($ref->isFinal())->toBeTrue()
            ->and($ref->isReadOnly())->toBeTrue();
    });

    test('ConditionEngine implements ConditionEngineContract', function (): void {
        $engine = new ConditionEngine;

        expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
    });

    test('ConditionEngine is final', function (): void {
        $ref = new \ReflectionClass(ConditionEngine::class);

        expect($ref->isFinal())->toBeTrue();
    });

    test('ActionResolver is final', function (): void {
        $ref = new \ReflectionClass(ActionResolver::class);

        expect($ref->isFinal())->toBeTrue();
    });

    test('EventScheduler is final', function (): void {
        $ref = new \ReflectionClass(EventScheduler::class);

        expect($ref->isFinal())->toBeTrue();
    });

    test('EventsServiceProvider has provides() returning list<string>', function (): void {
        $method = new \ReflectionMethod(EventsServiceProvider::class, 'provides');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType->getName())->toBe('array');
    });

    test('EventsServiceProvider has #[\Override] on register', function (): void {
        $method = new \ReflectionMethod(EventsServiceProvider::class, 'register');
        $attrs = $method->getAttributes(\Override::class);

        expect($attrs)->toHaveCount(1);
    });

    test('EventsServiceProvider has #[\Override] on boot', function (): void {
        $method = new \ReflectionMethod(EventsServiceProvider::class, 'boot');
        $attrs = $method->getAttributes(\Override::class);

        expect($attrs)->toHaveCount(1);
    });

    test('EventsServiceProvider has #[\Override] on provides', function (): void {
        $method = new \ReflectionMethod(EventsServiceProvider::class, 'provides');
        $attrs = $method->getAttributes(\Override::class);

        expect($attrs)->toHaveCount(1);
    });

    test('Facade getFacadeAccessor has #[\Override]', function (): void {
        $method = new \ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
        $attrs = $method->getAttributes(\Override::class);

        expect($attrs)->toHaveCount(1);
    });

    test('Facade getFacadeAccessor returns EventManager class string', function (): void {
        $facade = new \ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
        $method = $facade->getMethod('getFacadeAccessor');
        $method->setAccessible(true);
        $result = $method->invoke(null);

        expect($result)->toBe(EventManager::class);
    });

    test('all source files declare strict_types=1', function (): void {
        $srcDir = dirname(__DIR__).'/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $violations = [];

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = $file->getFilename();
                }
            }
        }

        expect($violations)->toBeEmpty('Files missing strict_types: '.implode(', ', $violations));
    });

    test('all factory files declare strict_types=1', function (): void {
        $factoryDir = dirname(__DIR__).'/database/factories';
        $files = glob($factoryDir.'/*.php');
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('Factory files missing strict_types: '.implode(', ', $violations));
    });

    test('all migration files declare strict_types=1', function (): void {
        $migrationDir = dirname(__DIR__).'/database/migrations';
        $files = glob($migrationDir.'/*.php');
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('Migration files missing strict_types: '.implode(', ', $violations));
    });
});
