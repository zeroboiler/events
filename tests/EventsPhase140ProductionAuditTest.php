<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

test('events version is 4.68.0 in composer.json', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['version'])->toBe('4.68.0');
});

test('events version badge in README matches composer.json', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    expect($readme)->toContain('version-4.68.0');
});

test('README test file count matches actual count on disk', function (): void {
    $testFiles = glob(base_path('tests/*.php'));
    $actualCount = is_array($testFiles) ? count($testFiles) : 0;

    $readme = file_get_contents(base_path('README.md'));
    expect($readme)->toContain("{$actualCount} test files");

    // Also verify the specific line "Run Pest test suite (N test files)"
    expect($readme)->toContain("({$actualCount} test files)");
});

test('README package structure tree has database/migrations as child of database', function (): void {
    $readme = file_get_contents(base_path('README.md'));

    // migrations should be indented with │   └── (a child of database/)
    expect($readme)->toContain('│   └── migrations/');
});

test('all source files have strict types declaration', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    $violations = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if (! str_contains($contents, 'declare(strict_types=1)')) {
            $violations[] = basename($file);
        }
    }

    expect($violations)->toBeEmpty('Missing strict_types in: '.implode(', ', $violations));
});

test('all source files have license headers', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    $violations = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if (! str_contains($contents, 'This file is part of ZeroBoiler')) {
            $violations[] = basename($file);
        }
    }

    expect($violations)->toBeEmpty('Missing license header in: '.implode(', ', $violations));
});

test('all classes in src are final', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    $nonFinal = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        // Find class declarations (skip "final class")
        preg_match_all('/^\s*(?!final\s)(?:abstract\s+)?class\s+(\w+)/m', $contents, $matches);
        if (! empty($matches[1])) {
            $nonFinal[basename($file)] = $matches[1];
        }
    }

    expect($nonFinal)->toBeEmpty('Non-final classes found: '.json_encode($nonFinal));
});

test('WildcardMatcher is readonly final class', function (): void {
    $contents = file_get_contents(base_path('src/WildcardMatcher.php'));
    expect($contents)->toContain('readonly final class WildcardMatcher');
});

test('all overridden methods have Override attribute', function (): void {
    $overridesVerified = [
        'EventsServiceProvider' => ['register', 'boot', 'provides'],
        'EventManager' => [],
        'Trigger' => ['getTable', 'boot', 'newFactory', 'casts'],
        'EventLog' => ['getTable', 'boot', 'newFactory', 'casts'],
        'Subscription' => ['getTable', 'boot', 'newFactory', 'casts'],
        'ConditionEngine' => ['matches'],
        'Facades\\EventManager' => ['getFacadeAccessor'],
    ];

    foreach ($overridesVerified as $class => $methods) {
        $path = base_path('src/'.str_replace('\\', '/', $class).'.php');
        if (! file_exists($path)) {
            continue;
        }
        $contents = file_get_contents($path);
        foreach ($methods as $method) {
            expect($contents)->toContain('#[\Override]')
                ->and($contents)->toContain("function {$method}(");
        }
    }
});

test('EventManager has typed promoted constructor properties', function (): void {
    $contents = file_get_contents(base_path('src/EventManager.php'));

    expect($contents)->toContain('protected readonly ConditionEngine $conditionEngine');
    expect($contents)->toContain('protected readonly ActionResolver $actionResolver');
    expect($contents)->toContain('protected readonly Container $app');
});

test('DomainEvent has readonly promoted constructor properties', function (): void {
    $contents = file_get_contents(base_path('src/Domain/DomainEvent.php'));

    expect($contents)->toContain('public readonly string $eventType');
    expect($contents)->toContain('public readonly array $payload');
});

test('no source file contains TODO/FIXME/HACK comments', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    $violations = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        foreach (['TODO:', 'FIXME:', 'HACK:', 'XXX:'] as $marker) {
            if (str_contains($contents, $marker)) {
                $violations[] = basename($file).' contains '.$marker;
            }
        }
    }

    expect($violations)->toBeEmpty('Found disallowed markers: '.implode('; ', $violations));
});

test('no source file contains setAccessible calls', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toContain('->setAccessible(');
    }
});

test('ServiceProvider provides array matches register bindings', function (): void {
    $contents = file_get_contents(base_path('src/EventsServiceProvider.php'));

    // verify provides() includes all registered services
    expect($contents)->toContain('EventManager::class');
    expect($contents)->toContain('ConditionEngine::class');
    expect($contents)->toContain('ConditionEngineContract::class');
    expect($contents)->toContain('ActionResolver::class');
    expect($contents)->toContain('TriggerBuilder::class');
    expect($contents)->toContain('SubscriptionBuilder::class');
    expect($contents)->toContain('EventScheduler::class');
});

test('Facade getFacadeAccessor returns EventManager class', function (): void {
    $contents = file_get_contents(base_path('src/Facades/EventManager.php'));
    expect($contents)->toContain('return \\ZeroBoiler\\Events\\EventManager::class');
});

test('config/events.php has all required keys', function (): void {
    $config = require base_path('config/events.php');

    expect($config)->toHaveKey('table_names');
    expect($config)->toHaveKey('queue');
    expect($config)->toHaveKey('retry');
    expect($config)->toHaveKey('retention');
    expect($config)->toHaveKey('subscriptions');
    expect($config)->toHaveKey('disabled');
    expect($config)->toHaveKey('wildcard_cache_ttl');

    // Nested keys
    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    expect($config['queue'])->toHaveKeys(['connection', 'queue']);
    expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
    expect($config['subscriptions'])->toHaveKeys(['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron']);
});

test('all 12 console commands are registered in ServiceProvider', function (): void {
    $contents = file_get_contents(base_path('src/EventsServiceProvider.php'));

    $commands = [
        'EventsListCommand',
        'EventsRegisterCommand',
        'EventsFireCommand',
        'EventsLogCommand',
        'EventsRetryCommand',
        'EventsEnableCommand',
        'EventsDisableCommand',
        'EventsHealthCommand',
        'EventsSubscribeCommand',
        'EventsUnsubscribeCommand',
        'EventsSubscriptionsCommand',
        'EventsRedeliverCommand',
    ];

    foreach ($commands as $command) {
        expect($contents)->toContain($command.'::class');
    }
});

test('phpstan.neon.dist uses max level', function (): void {
    $contents = file_get_contents(base_path('phpstan.neon.dist'));
    expect($contents)->toContain('level: max');
});

test('composer.json requires PHP 8.5+', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['require']['php'])->toBe('^8.5');
});

test('composer.json autoload PSR-4 is correct', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
});

test('ConditionEngine has 19 operators in match expression', function (): void {
    $contents = file_get_contents(base_path('src/ConditionEngine.php'));

    $operators = ['>', '>=', '<', '<=', '=', '===', '!=', '!==', 'in', 'not_in', 'contains', 'not_contains', 'between', 'null', 'not_null', 'empty', 'not_empty', 'starts_with', 'ends_with', 'matches'];

    foreach ($operators as $op) {
        expect($contents)->toContain("'$op'");
    }

    expect(count($operators))->toBe(19);
});

test('WildcardMatcher has Pure attribute on static methods', function (): void {
    $contents = file_get_contents(base_path('src/WildcardMatcher.php'));

    expect($contents)->toContain('#[\Pure]');
    expect($contents)->toContain('public static function matches(');
    expect($contents)->toContain('public static function findMatchingPatterns(');
    expect($contents)->toContain('public static function extractWildcards(');
});

test('models use config-driven table names', function (): void {
    $triggerContents = file_get_contents(base_path('src/Models/Trigger.php'));
    $eventLogContents = file_get_contents(base_path('src/Models/EventLog.php'));
    $subContents = file_get_contents(base_path('src/Models/Subscription.php'));

    expect($triggerContents)->toContain("config('events.table_names.triggers'");
    expect($eventLogContents)->toContain("config('events.table_names.event_logs'");
    expect($subContents)->toContain("config('events.table_names.subscriptions'");
});

test('EventLog has all status constants', function (): void {
    $contents = file_get_contents(base_path('src/Models/EventLog.php'));

    expect($contents)->toContain("STATUS_PENDING = 'pending'");
    expect($contents)->toContain("STATUS_DISPATCHED = 'dispatched'");
    expect($contents)->toContain("STATUS_COMPLETED = 'completed'");
    expect($contents)->toContain("STATUS_FAILED = 'failed'");
});

test('DomainEvent is immutable value object', function (): void {
    $contents = file_get_contents(base_path('src/Domain/DomainEvent.php'));

    expect($contents)->toContain('final class DomainEvent');
    expect($contents)->toContain('public readonly string $eventType');
    expect($contents)->toContain('public readonly array $payload');
    expect($contents)->toContain('public readonly UuidInterface $eventId');
    expect($contents)->toContain('public readonly DateTimeImmutable $occurredAt');
});

test('TriggerBuilder save validates empty event and no action', function (): void {
    $contents = file_get_contents(base_path('src/TriggerBuilder.php'));

    expect($contents)->toContain("Event name is required");
    expect($contents)->toContain("At least one action is required");
});

test('SubscriptionBuilder validates HTTP scheme', function (): void {
    $contents = file_get_contents(base_path('src/SubscriptionBuilder.php'));

    expect($contents)->toContain('Webhook URL must use HTTP or HTTPS protocol');
    expect($contents)->toContain('Webhook URL must be a valid URL');
    expect($contents)->toContain('Webhook URL is required for subscription');
});

test('database migrations exist for all 3 tables', function (): void {
    $migrations = glob(base_path('database/migrations/*.php'));
    $files = array_map(static fn (string $f): string => basename($f), $migrations);

    expect($files)->toContain('2024_01_01_000001_create_triggers_table.php');
    expect($files)->toContain('2024_01_01_000002_create_event_logs_table.php');
    expect($files)->toContain('2025_06_28_000001_create_event_subscriptions_table.php');
});

test('factories exist for all 3 models', function (): void {
    $factories = glob(base_path('database/factories/*.php'));
    $files = array_map(static fn (string $f): string => basename($f), $factories);

    expect($files)->toContain('TriggerFactory.php');
    expect($files)->toContain('EventLogFactory.php');
    expect($files)->toContain('SubscriptionFactory.php');
});

test('GitHub CI workflow exists', function (): void {
    expect(file_exists(base_path('.github/workflows/ci.yml')))->toBeTrue();
    expect(file_exists(base_path('.github/workflows/auto-fix.yml')))->toBeTrue();
});

test('CI workflow uses PHP 8.5', function (): void {
    $contents = file_get_contents(base_path('.github/workflows/ci.yml'));
    expect($contents)->toContain("php-version: '8.5'");
});

test('Pest.php registers EventsPhase140ProductionAuditTest', function (): void {
    $contents = file_get_contents(base_path('tests/Pest.php'));
    expect($contents)->toContain('EventsPhase140ProductionAuditTest.php');
});
