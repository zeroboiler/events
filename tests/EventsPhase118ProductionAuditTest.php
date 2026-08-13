<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 118 production audit — verify Carbon timestamp method usage
 * and README accuracy.
 */
it('EventManager uses getTimestamp() not deprecated timestamp property in sorting callback', function (): void {
    $source = file_get_contents(__DIR__.'/../src/EventManager.php');

    expect($source)->toBeString();

    // Verify getTimestamp() is used in the sorting callback
    expect(str_contains($source, '->getTimestamp()'))->toBeTrue(
        'EventManager::getMatchingTriggers() must use getTimestamp() method instead of deprecated timestamp property'
    );

    // Verify the old timestamp property access is NOT used in sorting context
    expect(str_contains($source, '?->timestamp ?? 0'))->toBeFalse(
        'EventManager must not use deprecated Carbon timestamp property for PHP 8.5 compliance'
    );

    expect(str_contains($source, '->timestamp ??'))->toBeFalse(
        'EventManager must not use deprecated Carbon timestamp property'
    );
});

it('README version badge matches composer.json version', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

    $composerVersion = is_array($composer) ? ($composer['version'] ?? '') : '';

    expect($composerVersion)->toBe('4.45.0', 'composer.json version should be 4.45.0');
    expect(str_contains($readme, "version-{$composerVersion}"))->toBeTrue(
        'README badge version must match composer.json version'
    );
});

it('README test file count is accurate', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');

    // Should NOT say "217+" (that was the old incorrect count)
    expect(str_contains($readme, '217+ test files'))->toBeFalse(
        'README should not reference 217+ test files (correct count is 197)'
    );

    // Should say "197 test files"
    expect(str_contains($readme, '197 test files'))->toBeTrue(
        'README should reference accurate test file count of 197'
    );
});

it('Changelog has v4.45.0 entry with timestamp fix description', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect(str_contains($readme, '### v4.45.0'))->toBeTrue(
        'README changelog must have v4.45.0 entry'
    );

    expect(str_contains($readme, 'getTimestamp()'))->toBeTrue(
        'v4.45.0 changelog must mention getTimestamp() fix'
    );
});

it('WildcardMatcher readonly final class with only static methods and #[Pure]', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue('WildcardMatcher must be final');
    expect($reflection->isReadOnly())->toBeTrue('WildcardMatcher must be readonly');

    // All public methods must be static
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue(
            "WildcardMatcher::{$method->getName()} must be static"
        );
    }

    // Static methods must have #[Pure]
    $staticMethods = $reflection->getMethods(ReflectionMethod::IS_STATIC);
    expect(count($staticMethods))->toBe(3, 'WildcardMatcher should have exactly 3 static methods');

    foreach ($staticMethods as $method) {
        $attrs = $method->getAttributes(\Attribute::class);
        $hasPure = false;
        foreach ($method->getAttributes() as $attr) {
            if ($attr->getName() === 'Pure' || $attr->getName() === '\\Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue(
            "WildcardMatcher::{$method->getName()} must have #[Pure] attribute"
        );
    }
});

it('ConditionEngine does not have #[Pure] on evaluateCondition (it calls safeRegexMatch)', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'evaluateCondition');

    $hasPure = false;
    foreach ($method->getAttributes() as $attr) {
        if ($attr->getName() === 'Pure' || $attr->getName() === '\\Pure') {
            $hasPure = true;
            break;
        }
    }

    expect($hasPure)->toBeFalse(
        'ConditionEngine::evaluateCondition() must NOT have #[Pure] — it may call safeRegexMatch() which uses ini_set()'
    );
});

it('ConditionEngine has #[Pure] on strictEquals, getNestedValue, contains, between', function (): void {
    $pureMethods = ['strictEquals', 'getNestedValue', 'contains', 'between'];

    foreach ($pureMethods as $methodName) {
        $method = new ReflectionMethod(ConditionEngine::class, $methodName);

        $hasPure = false;
        foreach ($method->getAttributes() as $attr) {
            if ($attr->getName() === 'Pure' || $attr->getName() === '\\Pure') {
                $hasPure = true;
                break;
            }
        }

        expect($hasPure)->toBeTrue(
            "ConditionEngine::{$methodName}() must have #[Pure] attribute"
        );
    }
});

it('safeRegexMatch does not have #[Pure] attribute', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');

    $hasPure = false;
    foreach ($method->getAttributes() as $attr) {
        if ($attr->getName() === 'Pure' || $attr->getName() === '\\Pure') {
            $hasPure = true;
            break;
        }
    }

    expect($hasPure)->toBeFalse(
        'ConditionEngine::safeRegexMatch() must NOT have #[Pure] — it uses ini_set() which is impure'
    );
});

it('ConditionEngine::matches has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');

    $hasOverride = false;
    foreach ($method->getAttributes() as $attr) {
        if ($attr->getName() === 'Override' || $attr->getName() === '\\Override') {
            $hasOverride = true;
            break;
        }
    }

    expect($hasOverride)->toBeTrue(
        'ConditionEngine::matches() must have #[Override] attribute (implements ConditionEngineContract)'
    );
});

it('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob("{$srcDir}/**/*.php");

    expect($files)->not->toBeEmpty('src/ directory should contain PHP files');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        $relativePath = str_replace(__DIR__.'/../', '', $file);

        expect(str_contains((string) $content, 'declare(strict_types=1)'))->toBeTrue(
            "{$relativePath} must have declare(strict_types=1)"
        );
    }
});

it('all factory and migration files have declare(strict_types=1)', function (): void {
    $dirs = [
        __DIR__.'/../database/factories',
        __DIR__.'/../database/migrations',
    ];

    foreach ($dirs as $dir) {
        $files = glob("{$dir}/*.php");
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(__DIR__.'/../', '', $file);

            expect(str_contains((string) $content, 'declare(strict_types=1)'))->toBeTrue(
                "{$relativePath} must have declare(strict_types=1)"
            );
        }
    }
});

it('EventManager class is final', function (): void {
    expect((new ReflectionClass(\ZeroBoiler\Events\EventManager::class))->isFinal())->toBeTrue();
});

it('EventManager has correct trait count', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getShortName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain('EscapesWildcardLike');
    expect($traitNames)->toContain('ManagesHistory');
    expect($traitNames)->toContain('ManagesSubscriptions');
    expect(count($traitNames))->toBe(3, 'EventManager should use exactly 3 traits');
});

it('composer.json has correct PHP and Laravel requirements', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    expect($composer['require']['illuminate/support'])->toBe('^13.0');
    expect($composer['require']['illuminate/database'])->toBe('^13.0');

    // Verify phpstan 2.x requirement
    expect($composer['require-dev']['phpstan/phpstan'])->toMatch('/^\^2\./');
});

it('config file has all 7 top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $expectedKeys = [
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ];

    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Config must have '{$key}' key");
    }

    expect(count($config))->toBe(7, 'Config should have exactly 7 top-level keys');
});

it('ServiceProvider provides() includes all 7 services', function (): void {
    $reflection = new ReflectionMethod(
        \ZeroBoiler\Events\EventsServiceProvider::class,
        'provides',
    );
    $method = $reflection;

    $hasOverride = false;
    foreach ($method->getAttributes() as $attr) {
        if ($attr->getName() === 'Override' || $attr->getName() === '\\Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('provides() must have #[Override]');

    // Read the source to verify the provides list
    $source = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');

    expect(str_contains($source, 'EventManager::class,'))->toBeTrue();
    expect(str_contains($source, 'ConditionEngine::class,'))->toBeTrue();
    expect(str_contains($source, 'ConditionEngineContract::class,'))->toBeTrue();
    expect(str_contains($source, 'ActionResolver::class,'))->toBeTrue();
    expect(str_contains($source, 'TriggerBuilder::class,'))->toBeTrue();
    expect(str_contains($source, 'SubscriptionBuilder::class,'))->toBeTrue();
    expect(str_contains($source, 'EventScheduler::class,'))->toBeTrue();
});
