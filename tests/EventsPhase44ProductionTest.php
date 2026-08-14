<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

use function PHPUnit\Framework\assertDirectoryExists;
use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertIsInt;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Phase 44 — Final production audit.
 *
 * Covers: CHANGELOG presence, composer.json autoload, rector.php,
 * .gitignore completeness, database directories, phpstan config,
 * license headers, facade completeness, method return types, config
 * consistency, and version badge accuracy.
 */
test('CHANGELOG.md exists and contains latest version', function (): void {
    assertFileExists(__DIR__.'/../CHANGELOG.md');

    $changelog = file_get_contents(__DIR__.'/../CHANGELOG.md');
    assertTrue($changelog !== false);
    assertStringContainsString('[1.83.0]', $changelog);
});

test('composer.json autoload PSR-4 structure is correct', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    assertTrue(isset($composer['autoload']['psr-4']['ZeroBoiler\\Events\\']));
    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
});

test('composer.json autoload-dev has test and factory namespaces', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Database\\Factories\\'])->toBe('database/factories/');
});

test('composer.json extra.laravel providers and aliases are set', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['providers'])->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
    expect($composer['extra']['laravel']['aliases'])->toHaveKey('EventManager');
});

test('rector.php exists and uses Laravel 130 set', function (): void {
    assertFileExists(__DIR__.'/../rector.php');
    $content = file_get_contents(__DIR__.'/../rector.php');
    assertStringContainsString('LaravelSetList::LARAVEL_130', $content);
    assertStringContainsString('declare(strict_types=1)', $content);
});

test('.gitignore contains essential entries', function (): void {
    $gitignore = file_get_contents(__DIR__.'/../.gitignore');
    assertTrue($gitignore !== false);
    assertStringContainsString('/vendor/', $gitignore);
    assertStringContainsString('phpstan.neon', $gitignore);
    assertStringContainsString('phpstan-baseline.neon', $gitignore);
    assertStringContainsString('composer.lock', $gitignore);
});

test('database/migrations directory contains 3 migration files', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');
    assertGreaterThanOrEqual(3, count($migrations));
});

test('database/factories directory contains 3 factory files', function (): void {
    $factories = glob(__DIR__.'/../database/factories/*.php');
    assertGreaterThanOrEqual(3, count($factories));
});

test('phpstan.neon.dist has level 9 and correct paths', function (): void {
    $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    assertStringContainsString('level: max', $neon);
    assertStringContainsString('paths:', $neon);
    assertStringContainsString('- src', $neon);
});

test('phpstan.neon.dist has ignoreErrors for Eloquent magic', function (): void {
    $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    assertStringContainsString('Access to an undefined property', $neon);
    assertStringContainsString('Call to an undefined static method', $neon);
    assertStringContainsString('Call to an undefined method', $neon);
    assertStringContainsString('database_path', $neon);
});

test('all source files have license header', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getRealPath());
        assertTrue(
            str_contains($content, 'This file is part of ZeroBoiler'),
            "License header missing in {$file->getPathname()}",
        );
    }
});

test('EventManager facade has @method annotations for all public methods', function (): void {
    $facade = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');
    assertTrue($facade !== false);

    $methods = [
        'static \\ZeroBoiler\\Events\\TriggerBuilder on(',
        'static void fire(',
        'static void fireModel(',
        'static bool enable(',
        'static bool disable(',
        'static void invalidateTriggerCache()',
        'static \\Illuminate\\Database\\Eloquent\\Collection<int',
        'static \\ZeroBoiler\\Events\\Models\\Trigger|null getTrigger(',
        'static bool deleteTrigger(',
        'static \\ZeroBoiler\\Events\\SubscriptionBuilder subscribe(',
        'static bool unsubscribe(',
        'static \\Illuminate\\Database\\Eloquent\\Collection<int, \\ZeroBoiler\\Events\\Models\\Subscription> listSubscriptions(',
        'static \\ZeroBoiler\\Events\\Models\\Subscription|null getSubscription(',
        'static string subscribeWebhook(',
        'static \\Illuminate\\Database\\Eloquent\\Collection<int, \\ZeroBoiler\\Events\\Models\\EventLog> getEventHistory(',
        'static int purgeLogs(',
        'static void executeTrigger(',
        'static array{',
    ];

    foreach ($methods as $method) {
        assertStringContainsString($method, $facade, "Facade missing @method for: {$method}");
    }
});

test('WebhookAction has getTimeout and getMaxFailures with correct return types', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);

    $getTimeout = $ref->getMethod('getTimeout');
    assertTrue($getTimeout->isPrivate());
    expect($getTimeout->getReturnType()->getName())->toBe('int');

    $getMaxFailures = $ref->getMethod('getMaxFailures');
    assertTrue($getMaxFailures->isPrivate());
    expect($getMaxFailures->getReturnType()->getName())->toBe('int');
});

test('EventLog casts method returns correct type', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Models\EventLog::class);
    $method = $ref->getMethod('casts');
    $returnType = $method->getReturnType();
    expect($returnType?->getName())->toBe('array');
});

test('DomainEvent fromArray handles all edge cases gracefully', function (): void {
    // Empty array
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([]);
    expect($event->eventType)->toBe('');

    // Non-string eventType
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['eventType' => 123, 'payload' => 'invalid']);
    expect($event->eventType)->toBe('');
    expect($event->payload)->toBe([]);

    // Invalid UUID — generates fresh
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => 'not-a-uuid',
    ]);
    expect($event->eventId->toString())->not->toBe('not-a-uuid');

    // Invalid datetime — uses now
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test.event',
        'occurredAt' => 'not-a-date',
    ]);
    expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);

    // Full roundtrip
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($original->toArray());
    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->occurredAt->format('U'))->toBe($original->occurredAt->format('U'));
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});

test('ManagesHistory getStats return structure shape', function (): void {
    $app = $this->createApplication();
    $manager = $app->make(\ZeroBoiler\Events\EventManager::class);

    $stats = $manager->getStats();

    // Verify structure shape (not empty because we just created triggers above potentially)
    expect($stats)->toHaveKeys([
        'total_logs',
        'total_triggers',
        'active_triggers',
        'completed',
        'failed',
        'pending',
        'dispatched',
        'success_rate',
        'failure_rate',
        'avg_duration_ms',
        'top_events',
        'top_failed_events',
    ]);

    expect($stats['total_logs'])->toBeInt();
    expect($stats['total_triggers'])->toBeInt();
    expect($stats['active_triggers'])->toBeInt();
    expect($stats['completed'])->toBeInt();
    expect($stats['failed'])->toBeInt();
    expect($stats['pending'])->toBeInt();
    expect($stats['dispatched'])->toBeInt();
});

test('all test files in Pest.php registered or standalone', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    assertTrue($pestContent !== false);

    $standaloneFiles = ['WildcardMatcherTest.php', 'EscapesWildcardLikeTest.php'];

    $testDir = __DIR__;
    $allTests = glob($testDir.'/*Test.php');
    assertNotEmpty($allTests);

    foreach ($allTests as $testFile) {
        $basename = basename($testFile);
        if (in_array($basename, $standaloneFiles, true)) {
            // Standalone tests should NOT be in Pest.php uses()
            assertTrue(
                ! str_contains($pestContent, $basename),
                "Standalone test {$basename} should not be in Pest.php uses()",
            );
        } else {
            assertTrue(
                str_contains($pestContent, $basename),
                "Test file {$basename} missing from Pest.php uses()",
            );
        }
    }
});

test('EventsUnsubscribeCommand casts id to string at assignment', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class);
    $method = $ref->getMethod('handle');
    $filename = file_get_contents($ref->getFileName());

    // Verify that $id is cast to string at assignment (not at usage)
    assertStringContainsString('$id = (string) $this->argument', $filename);
});

test('EventsSubscribeCommand casts event and url to string', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsSubscribeCommand::class);
    $filename = file_get_contents($ref->getFileName());

    assertStringContainsString("(string) \$event", $filename);
    assertStringContainsString("(string) \$url", $filename);
});

test('EventsRedeliverCommand has buildRedeliverBody private method', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsRedeliverCommand::class);
    $method = $ref->getMethod('buildRedeliverBody');
    assertTrue($method->isPrivate());
    expect($method->getReturnType()?->getName())->toBe('array');
});

test('version consistency between composer.json and README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'];
    expect($version)->toBe('1.83.0');
    assertStringContainsString("version-{$version}", $readme);
});
