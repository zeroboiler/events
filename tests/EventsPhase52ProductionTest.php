<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

// ─── Type Safety: is_string() guard on EventsListCommand ───────────────────

test('EventsListCommand uses is_string() type guard on event option', function (): void {
    $command = $this->app->make(\ZeroBoiler\Events\Console\EventsListCommand::class);

    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('handle');
    $content = file_get_contents($method->getFileName());
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $lines = array_slice(explode("\n", $content), $startLine - 1, $endLine - $startLine + 1);
    $methodBody = implode("\n", $lines);

    // Must use is_string() guard, not just !== null
    expect($methodBody)->toContain('is_string($eventFilter)');
});

// ─── Type Safety: is_string() guard on EventsSubscriptionsCommand ──────────

test('EventsSubscriptionsCommand uses is_string() type guard on event option', function (): void {
    $command = $this->app->make(\ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class);

    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('handle');
    $content = file_get_contents($method->getFileName());
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $lines = array_slice(explode("\n", $content), $startLine - 1, $endLine - $startLine + 1);
    $methodBody = implode("\n", $lines);

    // Must use is_string() guard
    expect($methodBody)->toContain('is_string($eventFilter)');
});

// ─── Type Safety: EventsLogCommand already has is_string() guard ────────────

test('EventsLogCommand uses is_string() type guard on event option', function (): void {
    $command = $this->app->make(\ZeroBoiler\Events\Console\EventsLogCommand::class);

    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('handle');
    $content = file_get_contents($method->getFileName());
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $lines = array_slice(explode("\n", $content), $startLine - 1, $endLine - $startLine + 1);
    $methodBody = implode("\n", $lines);

    expect($methodBody)->toContain('is_string($eventFilter)');
});

// ─── README test file count accuracy ───────────────────────────────────────

test('README test file count matches actual files on disk', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    $testFiles = glob(base_path('tests/*Test.php'));
    $actualCount = count($testFiles);

    // README package structure line
    expect($readme)->toContain("# {$actualCount} test files (Pest)");

    // Testing section
    expect($readme)->toContain("composer test        # Run Pest test suite ({$actualCount} test files)");
});

// ─── Version consistency ─────────────────────────────────────────────────

test('composer.json version matches README badge version', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    $readme = file_get_contents(base_path('../README.md'));

    $version = $composer['version'];
    expect($readme)->toContain("version-{$version}-blue");
});

// ─── All console commands with --event option use is_string() guard ─────────

test('all console commands with --event option use is_string() guard', function (): void {
    $commandFiles = glob(base_path('src/Console/*.php'));

    foreach ($commandFiles as $file) {
        $content = file_get_contents($file);

        // Only check commands that have --event option
        if (! str_contains($content, '--event=')) {
            continue;
        }

        $className = 'ZeroBoiler\\Events\\Console\\' . basename($file, '.php');

        // Check if the handle method uses is_string() on the event option
        $reflection = new ReflectionClass($className);
        $method = $reflection->getMethod('handle');
        $fileContent = file_get_contents($method->getFileName());
        $lines = array_slice(explode("\n", $fileContent), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $methodBody = implode("\n", $lines);

        expect($methodBody)->toContain('is_string(');
    }
});

// ─── No deprecated != null then cast pattern ────────────────────────────────

test('console commands do not use deprecated !== null guard for event option', function (): void {
    $commandFiles = glob(base_path('src/Console/*.php'));

    foreach ($commandFiles as $file) {
        $content = file_get_contents($file);

        if (! str_contains($content, '--event=')) {
            continue;
        }

        $className = 'ZeroBoiler\\Events\\Console\\' . basename($file, '.php');

        $reflection = new ReflectionClass($className);
        $method = $reflection->getMethod('handle');
        $fileContent = file_get_contents($method->getFileName());
        $lines = array_slice(explode("\n", $fileContent), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $methodBody = implode("\n", $lines);

        // Must not have the old pattern of !== null followed by (string) cast
        expect($methodBody)->not->toContain('!== null && $eventFilter');
    }
});

// ─── ServiceProvider registers all 11 console commands ──────────────────────

test('ServiceProvider registers exactly 11 console commands', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);

    $reflection = new ReflectionClass($provider);
    $bootMethod = $reflection->getMethod('boot');
    $fileContent = file_get_contents($bootMethod->getFileName());
    $lines = array_slice(explode("\n", $fileContent), $bootMethod->getStartLine() - 1, $bootMethod->getEndLine() - $bootMethod->getStartLine() + 1);
    $bootBody = implode("\n", $lines);

    // Count $this->commands([...]) entries
    $commandCount = 0;
    $commandClasses = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($commandClasses as $class) {
        $shortName = (new ReflectionClass($class))->getShortName();
        expect($bootBody)->toContain($shortName);
        $commandCount++;
    }

    expect($commandCount)->toBe(11);
});

// ─── Config completeness check ─────────────────────────────────────────────

test('config file has all required top-level keys', function (): void {
    $config = require base_path('config/events.php');

    expect($config)->toHaveKeys([
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'wildcard_cache_ttl',
    ]);

    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    expect($config['queue'])->toHaveKeys(['connection', 'queue']);
    expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
    expect($config['retention'])->toHaveKeys(['days', 'include_pending']);
    expect($config['subscriptions'])->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
    ]);
});

// ─── All source files use strict types ─────────────────────────────────────

test('all source files declare strict_types', function (): void {
    $sourceFiles = glob(base_path('src/**/*.php'), GLOB_BRACE);

    foreach ($sourceFiles as $file) {
        $content = file_get_contents($file);
        $relativePath = str_replace(base_path(), '', $file);
        expect($content)->toContain("declare(strict_types=1)", "File {$relativePath} missing strict_types declaration");
    }
});

// ─── All core classes are final ────────────────────────────────────────────

test('all core classes are final', function (): void {
    $finalClasses = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events\ConditionEngine::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        \ZeroBoiler\Events\SubscriptionBuilder::class,
        \ZeroBoiler\Events\WildcardMatcher::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
    ];

    foreach ($finalClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("Class {$class} should be final");
    }
});

// ─── Pest.php Phase52 is registered ────────────────────────────────────────

test('Pest.php includes EventsPhase52ProductionTest', function (): void {
    $pestContent = file_get_contents(base_path('tests/Pest.php'));
    expect($pestContent)->toContain('EventsPhase52ProductionTest.php');
});
