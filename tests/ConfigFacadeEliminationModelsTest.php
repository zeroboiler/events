<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

it('ensures no config() facade calls remain in model getTable() methods', function (): void {
    $modelFiles = [
        'Trigger' => __DIR__.'/../../src/Models/Trigger.php',
        'EventLog' => __DIR__.'/../../src/Models/EventLog.php',
        'Subscription' => __DIR__.'/../../src/Models/Subscription.php',
    ];

    foreach ($modelFiles as $name => $path) {
        $content = file_get_contents($path);
        if ($content === false) {
            fail("Could not read {$name} model file at {$path}");
        }

        // Check that config() facade is NOT used in getTable()
        if (preg_match('/function\s+getTable\s*\([^)]*\)\s*:\s*string\s*\{[^}]*config\(/s', $content)) {
            fail("{$name}::getTable() still uses config() facade — use app('config') instead.");
        }

        // Verify the file has the ConfigRepository import
        expect($content)
            ->toContain('ConfigRepository')
            ->toContain('app(\'config\')');
    }

    expect(true)->toBeTrue();
});

it('ensures no config() facade calls remain in Subscription::getConfigValue()', function (): void {
    $path = __DIR__.'/../../src/Models/Subscription.php';
    $content = file_get_contents($path);
    if ($content === false) {
        fail('Could not read Subscription model file');
    }

    // Verify getConfigValue uses app('config') and not config() facade
    if (preg_match('/function\s+getConfigValue\s*\([^)]*\)\s*:\s*mixed\s*\{[^}]*[^a]config\(/s', $content)) {
        fail('Subscription::getConfigValue() uses config() facade — use app(\'config\') instead.');
    }
});

it('ensures no static Config facade usage in source files', function (): void {
    $directory = new RecursiveDirectoryIterator(__DIR__.'/../../src');
    $iterator = new RecursiveIteratorIterator($directory);
    $phpFiles = new RegexIterator($iterator, '/\.php$/');

    $violations = [];
    foreach ($phpFiles as $file) {
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        // Allow Config facade in import/use statements (for type-hinting)
        // but not in actual calls: Config::get, Config::set, etc.
        if (preg_match('/(?:^|(?<!=)\s)Config::(?:get|set|has|all)\s*\(/m', $content)) {
            $relativePath = str_replace(__DIR__.'/../../', '', $file->getPathname());
            $violations[] = $relativePath;
        }
    }

    expect($violations)->toBeEmpty(
        implode("\n", ['Config facade usage found in:', ...$violations])
    );
});
