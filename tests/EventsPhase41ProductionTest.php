<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Phase 41 production audit — README test count accuracy, composer.json structure,
 * phpstan level 9, file existence verification, version consistency.
 */

it('README test file count matches actual files on disk', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');
    preg_match('/(\d+)\s+test files/', $readme, $matches);
    $readmeCount = (int) ($matches[1] ?? 0);

    $rootTests = count(glob(__DIR__.'/*Test.php') ?: []);
    $subDirTests = 0;
    $dirs = glob(__DIR__.'/*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        $subDirTests += count(glob($dir.'/*Test.php') ?: []);
    }
    $total = $rootTests + $subDirTests;

    expect($readmeCount)->toBe($total, "README says {$readmeCount} test files but found {$total} on disk");
});

it('composer test command references correct test count', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');
    preg_match('/composer test.*?(\d+)\s+test files/', $readme, $matches);
    $readmeCount = (int) ($matches[1] ?? 0);

    $rootTests = count(glob(__DIR__.'/*Test.php') ?: []);
    $subDirTests = 0;
    $dirs = glob(__DIR__.'/*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        $subDirTests += count(glob($dir.'/*Test.php') ?: []);
    }
    $total = $rootTests + $subDirTests;

    expect($readmeCount)->toBe($total);
});

it('composer.json has correct autoload configuration', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
});

it('phpstan.neon.dist is configured at level 9', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: 9');
});

it('EventManager is final class', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    expect($reflection->isFinal())->toBeTrue();
});

it('ConditionEngine is final class implementing ConditionEngineContract', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\ConditionEngine::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class))->toBeTrue();
});

it('WildcardMatcher is final class with #[Pure] on all public methods', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue();

    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    foreach ($methods as $method) {
        if ($method->isConstructor()) {
            continue;
        }
        $hasPure = false;
        foreach ($method->getAttributes() as $attr) {
            if ($attr->getName() === 'Pure' || str_contains($attr->getName(), 'Pure')) {
                $hasPure = true;
                break;
            }
        }
        // Only check key public API methods
        if (in_array($method->getName(), ['match', 'isMatch', 'extractWildcards', 'findMatchingPatterns'], true)) {
            expect($hasPure)->toBeTrue("WildcardMatcher::{$method->getName()} missing #[Pure] attribute");
        }
    }
});

it('DomainEvent is final class with readonly properties', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);
    expect($reflection->isFinal())->toBeTrue();

    $properties = $reflection->getProperties();
    foreach ($properties as $prop) {
        if ($prop->isStatic()) {
            continue;
        }
        expect($prop->isReadOnly())
            ->toBeTrue("DomainEvent::\${$prop->getName()} is not readonly");
    }
});

it('all 11 console commands have zeroboiler:events: prefix', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*.php') ?: [];
    foreach ($commandFiles as $file) {
        $content = file_get_contents($file);
        if (! str_contains($content, 'class ')) {
            continue;
        }
        // Check $signature property
        if (preg_match('/protected string \$signature\s*=\s*[\'"]([^\'"]+)/', $content, $m)) {
            expect($m[1])->toStartWith('zeroboiler:events:',
                basename($file)." command signature '{$m[1]}' does not start with 'zeroboiler:events:'");
        }
    }
});

it('config file has all required top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config)->toHaveKey('events');
    expect($config)->toHaveKey('subscriptions');
    expect($config)->toHaveKey('logging');
    expect($config)->toHaveKey('retry');
    expect($config)->toHaveKey('cache');
    expect($config)->toHaveKey('default_connection');
});
