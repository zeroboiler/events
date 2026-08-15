<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest Test: Config Facade Elimination Verification
|--------------------------------------------------------------------------
|
| Verifies that no source file in the src/ directory directly references
| the Illuminate\Support\Facades\Config facade. All config access should
| go through container-injected ConfigRepository for improved testability.
|
*/

uses(TestCase::class);

test('no source file imports Config facade', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $violations = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }
        if (str_contains($content, 'use Illuminate\\Support\\Facades\\Config;')) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBeEmpty(
        'No source file should import Config facade. Found violations: '.implode(', ', $violations),
    );
});

test('no source file calls Config::get() or Config::set()', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $violations = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }
        // Check for Config::get, Config::set, Config::has patterns
        if (preg_match('/Config::(get|set|has|push|prepend|forget)\s*\(/', $content)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBeEmpty(
        'No source file should call Config:: facade. Found violations: '.implode(', ', $violations),
    );
});

test('GetsWebhookTimeout trait uses container config', function (): void {
    $traitPath = __DIR__.'/../src/Concerns/GetsWebhookTimeout.php';
    $content = file_get_contents($traitPath);
    expect($content)->not->toBeFalse();
    expect($content)->toContain('ConfigRepository');
    expect($content)->not->toContain('use Illuminate\\Support\\Facades\\Config;');
    expect($content)->toContain('getWebhookConfig()');
});

test('WebhookAction uses getWebhookConfig for max_failures', function (): void {
    $path = __DIR__.'/../src/Actions/WebhookAction.php';
    $content = file_get_contents($path);
    expect($content)->not->toBeFalse();
    expect($content)->not->toContain('Config::get(');
    expect($content)->toContain('getWebhookConfig()');
    expect($content)->not->toContain('use Illuminate\\Support\\Facades\\Config;');
});

test('Subscription model uses getConfigValue for config access', function (): void {
    $path = __DIR__.'/../src/Models/Subscription.php';
    $content = file_get_contents($path);
    expect($content)->not->toBeFalse();
    expect($content)->not->toContain('Config::get(');
    expect($content)->toContain('getConfigValue(');
    expect($content)->not->toContain('use Illuminate\\Support\\Facades\\Config;');
});

test('DispatchTriggerJob uses container config', function (): void {
    $path = __DIR__.'/../src/Jobs/DispatchTriggerJob.php';
    $content = file_get_contents($path);
    expect($content)->not->toBeFalse();
    expect($content)->not->toContain('Config::get(');
    expect($content)->toContain('resolveConfig(');
    expect($content)->toContain('ConfigRepository');
    expect($content)->not->toContain('use Illuminate\\Support\\Facades\\Config;');
});

test('EventsHealthCommand uses getConfig() method', function (): void {
    $path = __DIR__.'/../src/Console/EventsHealthCommand.php';
    $content = file_get_contents($path);
    expect($content)->not->toBeFalse();
    expect($content)->not->toContain('Config::get(');
    expect($content)->toContain('getConfig()');
    expect($content)->toContain('ConfigRepository');
    expect($content)->not->toContain('use Illuminate\\Support\\Facades\\Config;');
});
