<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 148 production audit — verifies README accuracy, PHP 8.5 compliance,
 * ServiceProvider bindings, config completeness, version consistency,
 * model factory/state coverage, and migration integrity.
 */
it('has correct version badge in README', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('4.76.0');
});

it('has correct test file count in README', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('226 test files');
    expect($readme)->toContain('5 support');
});

it('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(base_path('../composer.json')), true);
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain((string) $composer['version']);
});

it('has strict_types in every source file', function (): void {
    $files = glob_recursive(base_path('src').'/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

it('has license header in every source file', function (): void {
    $files = glob_recursive(base_path('src').'/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('ZeroBoiler', "Missing license header in {$file}");
    }
});

it('EventManager constructor uses dependency injection (no global helpers)', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getConstructor();
    $params = $ctor->getParameters();
    expect(count($params))->toBe(3);
    // All params should be typed
    foreach ($params as $param) {
        expect($param->getType())->not->toBeNull(
            "Parameter \${$param->getName()} has no type in EventManager constructor",
        );
    }
});

it('all public EventManager methods have return types', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "Method {$method->getName()} in EventManager has no return type",
        );
    }
});

it('WildcardMatcher is readonly final', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

it('ConditionEngine is final', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    expect($ref->isFinal())->toBeTrue();
});

it('DomainEvent is readonly final with all readonly properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();

    foreach ($ref->getProperties() as $prop) {
        expect($prop->isReadOnly())->toBeTrue(
            "Property {$prop->getName()} on DomainEvent is not readonly",
        );
    }
});

it('Triggerable interface handle method has void return type', function (): void {
    $ref = new ReflectionClass(Triggerable::class);
    $method = $ref->getMethod('handle');
    expect($method->getReturnType()?->getName())->toBe('void');
});

it('ServiceProvider has #[Override] on register, boot, and provides', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);
    $register = $ref->getMethod('register');
    $boot = $ref->getMethod('boot');
    $provides = $ref->getMethod('provides');

    foreach ([$register, $boot, $provides] as $method) {
        $attrs = $method->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue(
            "Method {$method->getName()} in EventsServiceProvider is missing #[Override]",
        );
    }
});

it('ServiceProvider provides returns all 7 expected services', function (): void {
    $provider = $this->app->getProvider(EventsServiceProvider::class);
    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides->count())->toBe(7);
});

it('config has all required top-level keys', function (): void {
    $config = config('events');
    expect($config)->toHaveKey('table_names');
    expect($config)->toHaveKey('queue');
    expect($config)->toHaveKey('retry');
    expect($config)->toHaveKey('retention');
    expect($config)->toHaveKey('subscriptions');
    expect($config)->toHaveKey('disabled');
    expect($config)->toHaveKey('wildcard_cache_ttl');
});

it('config table_names has all three tables', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toHaveKey('triggers');
    expect($tables)->toHaveKey('event_logs');
    expect($tables)->toHaveKey('subscriptions');
});

it('config subscription settings are complete', function (): void {
    $subs = config('events.subscriptions');
    expect($subs)->toHaveKey('auto_generate_secret');
    expect($subs)->toHaveKey('max_failures');
    expect($subs)->toHaveKey('timeout');
    expect($subs)->toHaveKey('signature_algorithm');
    expect($subs)->toHaveKey('cleanup_cron');
});

it('config retention settings are complete', function (): void {
    $ret = config('events.retention');
    expect($ret)->toHaveKey('days');
    expect($ret)->toHaveKey('include_pending');
    expect($ret)->toHaveKey('schedule_cron');
});

it('phpstan.neon.dist uses max level', function (): void {
    $config = file_get_contents(base_path('../phpstan.neon.dist'));
    expect($config)->toContain('level: max');
    expect($config)->toContain('reportUnmatchedIgnoredErrors: true');
});

it('README has all required sections', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('## Table of Contents');
    expect($readme)->toContain('## API Reference');
    expect($readme)->toContain('## Production Deployment Checklist');
    expect($readme)->toContain('## Security Considerations');
    expect($readme)->toContain('## Troubleshooting');
    expect($readme)->toContain('## Architecture');
});

it('README changelog has v4.76.0 entry', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('### v4.76.0');
});

it('Pest.php registers this test file', function (): void {
    $pestContent = file_get_contents(base_path('Pest.php'));
    expect($pestContent)->toContain('EventsPhase148ProductionAuditTest');
});

it('composer.json requires PHP ^8.5', function (): void {
    $composer = json_decode(file_get_contents(base_path('../composer.json')), true);
    expect($composer['require']['php'])->toBe('^8.5');
});

it('composer.json requires illuminate/contracts ^13.0', function (): void {
    $composer = json_decode(file_get_contents(base_path('../composer.json')), true);
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
});

it('all migration files have strict_types', function (): void {
    $files = glob(base_path('../database/migrations').'/*.php');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

it('all factory files have strict_types', function (): void {
    $files = glob(base_path('../database/factories').'/*.php');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

it('no source files use deprecated array_last or array_first', function (): void {
    $files = glob_recursive(base_path('src').'/*.php');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toContain('array_last(', "Deprecated array_last() found in {$file}");
        expect($content)->not->toContain('array_first(', "Deprecated array_first() found in {$file}");
    }
});

it('no source files use setAccessible (removed in PHP 8.5)', function (): void {
    $files = glob_recursive(base_path('src').'/*.php');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toContain('setAccessible', "setAccessible() found in {$file} — removed in PHP 8.5");
    }
});

it('DispatchTriggerJob has config-driven tries', function (): void {
    config(['events.retry.tries' => 5]);
    $job = new DispatchTriggerJob('test-id', 'test.event', []);
    expect($job->tries)->toBe(5);
});

/**
 * Recursively glob for files.
 */
function glob_recursive(string $pattern, int $flags = 0): array
{
    $files = glob($pattern, $flags);
    $dirs = glob(dirname($pattern).'/*', GLOB_ONLYDIR | GLOB_NOSORT);

    foreach ($dirs as $dir) {
        $files = array_merge(
            $files,
            glob_recursive($dir.'/'.basename($pattern), $flags),
        );
    }

    return $files;
}
