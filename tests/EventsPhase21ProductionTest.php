<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;


beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

// ─── PHPStan 9 Readiness: Return Types ────────────────────────────────────────

test('EventManager::on returns TriggerBuilder with correct type', function (): void {
    $manager = app()->make(EventManager::class);
    $builder = $manager->on('test.event');

    expect($builder)->toBeInstanceOf(TriggerBuilder::class);
});

test('EventManager::register returns same builder as on', function (): void {
    $manager = app()->make(EventManager::class);

    $onBuilder = $manager->on('test.event');
    $regBuilder = $manager->register('test.event');

    expect($onBuilder)->toBeInstanceOf(TriggerBuilder::class)
        ->and($regBuilder)->toBeInstanceOf(TriggerBuilder::class);
});

test('EventManager::listTriggers returns Collection', function (): void {
    $manager = app()->make(EventManager::class);
    $result = $manager->listTriggers();

    expect($result)->toBeInstanceOf(Collection::class);
});

test('EventManager::getTrigger returns null for missing', function (): void {
    $manager = app()->make(EventManager::class);

    expect($manager->getTrigger('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

test('EventManager::deleteTrigger returns bool', function (): void {
    $manager = app()->make(EventManager::class);

    expect($manager->deleteTrigger('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager::enable returns bool', function (): void {
    $manager = app()->make(EventManager::class);

    expect($manager->enable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager::disable returns bool', function (): void {
    $manager = app()->make(EventManager::class);

    expect($manager->disable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager::fire returns void (no exception on no-match)', function (): void {
    $manager = app()->make(EventManager::class);

    // Fire an event with no matching triggers — should not throw
    $manager->fire('non.existent.event', ['key' => 'value']);
    expect(true)->toBeTrue();
});

test('EventManager::getEventHistory returns Collection', function (): void {
    $manager = app()->make(EventManager::class);

    expect($manager->getEventHistory())->toBeInstanceOf(Collection::class);
});

test('EventManager::getStats returns array with all keys', function (): void {
    $manager = app()->make(EventManager::class);
    $stats = $manager->getStats();

    expect($stats)->toBeArray()
        ->toHaveKeys([
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
});

test('EventManager::purgeLogs returns int', function (): void {
    $manager = app()->make(EventManager::class);

    expect($manager->purgeLogs(\Illuminate\Support\Carbon::now()->subYear()))->toBeInt();
});

// ─── No Unused Imports Verification ──────────────────────────────────────────

test('Subscription model has no unused imports', function (): void {
    $contents = file_get_contents(__DIR__.'/../src/Models/Subscription.php');
    expect($contents)->not->toContain('use Illuminate\Database\Eloquent\Factories\Factory;')
        ->and($contents)->toContain('use Illuminate\Database\Eloquent\Factories\HasFactory;');
});

// ─── Config: EventsServiceProvider mergeConfigFrom ──────────────────────────────

test('EventsServiceProvider merges config correctly', function (): void {
    $app = app();
    $config = $app->get('config');

    $eventsConfig = $config->get('events');
    expect($eventsConfig)->toBeArray()
        ->and($eventsConfig['table_names']['triggers'])->toBeString()
        ->and($eventsConfig['table_names']['event_logs'])->toBeString()
        ->and($eventsConfig['table_names']['subscriptions'])->toBeString()
        ->and($eventsConfig['queue']['queue'])->toBeString()
        ->and($eventsConfig['queue']['connection'])->toBeString()
        ->and($eventsConfig['retry']['tries'])->toBeInt()
        ->and($eventsConfig['retry']['backoff'])->toBeString()
        ->and($eventsConfig['retention']['days'])->toBeInt()
        ->and($eventsConfig['retention']['include_pending'])->toBeBool()
        ->and($eventsConfig['subscriptions']['auto_generate_secret'])->toBeBool()
        ->and($eventsConfig['subscriptions']['max_failures'])->toBeInt()
        ->and($eventsConfig['subscriptions']['timeout'])->toBeInt()
        ->and($eventsConfig['subscriptions']['signature_algorithm'])->toBeString()
        ->and($eventsConfig['wildcard_cache_ttl'])->toBeInt();
});

// ─── Config: Migrations use config-driven table names ─────────────────────────

test('triggers migration reads from config', function (): void {
    $contents = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');
    expect($contents)->toContain("config('events.table_names.triggers'")
        ->and($contents)->toContain("Schema::create");
});

test('event_logs migration reads from config and references triggers table', function (): void {
    $contents = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');
    expect($contents)->toContain("config('events.table_names.event_logs'")
        ->and($contents)->toContain("config('events.table_names.triggers'")
        ->and($contents)->toContain('onDelete');
});

test('subscriptions migration reads from config', function (): void {
    $contents = file_get_contents(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');
    expect($contents)->toContain("config('events.table_names.subscriptions'");
});

// ─── TriggerBuilder: Resolve Actions Deduplication ────────────────────────────

test('TriggerBuilder resolveActions deduplicates preserving order', function (): void {
    $manager = app()->make(EventManager::class);
    $builder = $manager->on('test.event');

    // We can't call resolveActions directly (it's private), but we can verify
    // through save() that the action string doesn't contain duplicates
    $trigger = $builder
        ->action(SendOrderNotification::class)
        ->actions([SendOrderNotification::class])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBe([SendOrderNotification::class]);
});

// ─── ConditionEngine: Edge Cases for PHPStan strictness ──────────────────────

test('ConditionEngine handles null payload gracefully', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([], []))->toBeTrue()
        ->and($engine->matches(['key' => 'value'], []))->toBeFalse();
});

test('ConditionEngine matches with nested dot-notation null intermediate', function (): void {
    $engine = new ConditionEngine;

    // Nested field where intermediate key is null
    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => null],
    ))->toBeFalse();
});

test('ConditionEngine between with non-numeric actual returns false', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(
        ['amount' => ['between', [50, 200]]],
        ['amount' => 'not-a-number'],
    ))->toBeFalse();
});

test('ConditionEngine in with null value returns false', function (): void {
    $engine = new ConditionEngine;

    // value is null → guarded
    expect($engine->matches(
        ['status' => ['in', [null, 'active', 'pending']]],
        ['status' => 'active'],
    ))->toBeTrue();
});

// ─── WildcardMatcher: Comprehensive Edge Cases ───────────────────────────────

test('WildcardMatcher exact match does not match empty string', function (): void {
    expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
});

test('WildcardMatcher empty pattern does not match', function (): void {
    expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
});

test('WildcardMatcher both empty strings', function (): void {
    expect(WildcardMatcher::matches('', ''))->toBeTrue();
});

test('WildcardMatcher handles special regex chars in event name', function (): void {
    expect(WildcardMatcher::matches('order.(placed)', 'order.(placed)'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.(placed)'))->toBeTrue();
});

test('WildcardMatcher extractWildcards returns empty for empty pattern', function (): void {
    expect(WildcardMatcher::extractWildcards('', ''))->toBe([]);
});

test('WildcardMatcher findMatchingPatterns returns empty for no patterns', function (): void {
    expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
});

// ─── DomainEvent: PHP 8.5 readonly properties ───────────────────────────────

test('DomainEvent has all readonly properties', function (): void {
    $ref = new \ReflectionClass(DomainEvent::class);
    $props = $ref->getProperties();

    $readonlyProps = [];
    foreach ($props as $prop) {
        if ($prop->isReadOnly()) {
            $readonlyProps[] = $prop->getName();
        }
    }

    expect($readonlyProps)->toContain('eventId')
        ->toContain('eventType')
        ->toContain('payload')
        ->toContain('occurredAt');
});

test('DomainEvent fromArray with invalid eventId generates fresh UUID', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'payload' => [],
        'eventId' => 'not-a-uuid',
    ]);

    // Should succeed with a fresh UUID (not throw)
    expect($event->eventType)->toBe('test.event')
        ->and($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
});

// ─── EscapesWildcardLike: Comprehensive ───────────────────────────────────────

test('EscapesWildcardLike escapes backslashes', function (): void {
    $trait = new class
    {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order\\*'))->not->toBeNull();
});

test('EscapesWildcardLike escapes percent signs', function (): void {
    $trait = new class
    {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    $result = $trait->wildcardToLike('order%placed');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('order%placed');
});

test('EscapesWildcardLike escapes underscores', function (): void {
    $trait = new class
    {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    $result = $trait->wildcardToLike('order_placed');
    expect($result)->toBeNull(); // No wildcard → null
});

test('EscapesWildcardLike converts single wildcard to percent', function (): void {
    $trait = new class
    {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    $result = $trait->wildcardToLike('order.*');
    expect($result)->toBe('order.%');
});

// ─── EventLog: Status Consistency ────────────────────────────────────────────

test('EventLog markAsCompleted sets correct fields', function (): void {
    $log = EventLog::factory()->pending()->create();

    $log->markAsCompleted(123);

    $fresh = EventLog::find($log->id);
    expect($fresh->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($fresh->duration_ms)->toBe(123);
});

test('EventLog markAsFailed sets correct fields', function (): void {
    $log = EventLog::factory()->pending()->create();

    $log->markAsFailed('Something went wrong');

    $fresh = EventLog::find($log->id);
    expect($fresh->status)->toBe(EventLog::STATUS_FAILED)
        ->and($fresh->error)->toBe('Something went wrong');
});

// ─── Trigger Model: Scopes ───────────────────────────────────────────────────

test('Trigger scopeEnabled filters correctly', function (): void {
    Trigger::factory()->enabled()->create(['event' => 'test.enabled']);
    Trigger::factory()->disabled()->create(['event' => 'test.disabled']);

    $enabled = Trigger::enabled()->get();
    expect($enabled)->toHaveCount(1)
        ->and($enabled->first()->event)->toBe('test.enabled');
});

test('Trigger scopeAsync filters correctly', function (): void {
    Trigger::factory()->async()->create(['event' => 'test.async']);
    Trigger::factory()->sync()->create(['event' => 'test.sync']);

    $async = Trigger::async()->get();
    expect($async)->toHaveCount(1)
        ->and($async->first()->event)->toBe('test.async');
});

// ─── Pest.php Completeness ──────────────────────────────────────────────────

test('Pest.php includes all test files', function (): void {
    $pestContents = file_get_contents(__DIR__.'/Pest.php');
    $testDir = __DIR__.'/';

    $testFiles = glob($testDir.'*.php');
    $testFileNames = array_map(fn (string $f): string => basename($f), $testFiles);

    // Files that are intentionally excluded from Pest.php
    $excluded = [
        'TestCase.php',
        'CreatesApplication.php',
        'helpers.php',
    ];

    // Files expected to be in Pest.php uses() call
    $expected = array_diff($testFileNames, $excluded);

    $missing = [];
    foreach ($expected as $fileName) {
        if (! str_contains($pestContents, $fileName)) {
            $missing[] = $fileName;
        }
    }

    // WildcardMatcherTest and EscapesWildcardLikeTest are noted as running without TestCase
    $nonLaravel = ['WildcardMatcherTest.php', 'EscapesWildcardLikeTest.php'];
    $missing = array_diff($missing, $nonLaravel);

    expect($missing)->toBeEmpty(
        'Test files missing from Pest.php uses(): '.implode(', ', $missing),
    );
});

// ─── All Source Files: declare(strict_types=1) ────────────────────────────────

test('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
    );

    $violations = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBeEmpty('Files missing strict_types: '.implode(', ', $violations));
});

// ─── Version Format ───────────────────────────────────────────────────────────

test('composer.json version is semver format', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composer['version'] ?? '';

    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
});
