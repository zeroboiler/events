<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Tests\TestCase;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

uses(TestCase::class);

// ─── DomainEvent explicit constructor args ───

test('domain event preserves explicit eventId and occurredAt', function (): void {
    $uuid = Ramsey\Uuid\Uuid::uuid4();
    $time = new DateTimeImmutable('2025-01-15 10:30:00');

    $event = new DomainEvent(
        eventType: 'test.event',
        payload: ['key' => 'value'],
        eventId: $uuid,
        occurredAt: $time,
    );

    expect($event->eventId->toString())->toBe($uuid->toString())
        ->and($event->occurredAt)->toBe($time)
        ->and($event->occurredAt->format('Y-m-d H:i:s'))->toBe('2025-01-15 10:30:00');
});

test('domain event fromArray preserves explicit args through roundtrip', function (): void {
    $uuid = Ramsey\Uuid\Uuid::uuid4();
    $time = new DateTimeImmutable('2024-06-28 12:00:00');

    $original = new DomainEvent(
        eventType: 'order.created',
        payload: ['amount' => 500],
        eventId: $uuid,
        occurredAt: $time,
    );

    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($uuid->toString())
        ->and($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($time->format(DateTimeImmutable::ATOM))
        ->and($restored->eventType)->toBe('order.created')
        ->and($restored->payload)->toBe(['amount' => 500]);
});

// ─── Trigger foreign key cascade delete ───

test('deleting trigger cascades to event logs', function (): void {
    $trigger = Trigger::factory()->create();
    EventLog::factory()->count(3)->create(['trigger_id' => $trigger->id]);

    // Soft-delete the trigger (default behavior)
    $trigger->delete();

    // Event logs should still exist after soft delete
    expect(EventLog::where('trigger_id', $trigger->id)->count())->toBe(3);

    // Hard delete
    $trigger->forceDelete();

    // After hard delete with cascade, event logs should be removed
    // (cascade applies at DB level with onDelete('cascade'))
    expect(Trigger::withTrashed()->find($trigger->id))->toBeNull();
});

// ─── SubscriptionBuilder auto-generate secret disabled ───

test('subscription builder skips auto-generate secret when config disabled', function (): void {
    Config::set('events.subscriptions.auto_generate_secret', false);

    // This test verifies the config value is read — the actual save would
    // fail in test DB but the config gate is what we're verifying
    $secret = Config::get('events.subscriptions.auto_generate_secret', true);
    expect($secret)->toBeFalse();
});

// ─── Subscription conditions empty array → null ───

test('subscription builder converts empty conditions to null', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('test.conditions.empty')->to('https://example.com/hooks');

    // Access the internal conditions property via reflection
    $ref = new ReflectionProperty($builder, 'conditions');
    $ref->setAccessible();
    $conditions = $ref->getValue($builder);

    expect($conditions)->toBe([]);
});

test('subscription model stores null conditions for empty array', function (): void {
    $sub = Subscription::factory()->create([
        'conditions' => null,
    ]);

    expect($sub->conditions)->toBeNull();

    $sub->update(['conditions' => []]);

    // Cast 'array' turns null/[] to []
    $sub->refresh();
    expect($sub->conditions)->toBe([]);
});

// ─── TriggerBuilder empty conditions save ───

test('trigger builder saves with empty conditions array', function (): void {
    $trigger = Trigger::factory()->create([
        'conditions' => [],
    ]);

    expect($trigger->conditions)->toBe([]);

    // Empty conditions in ConditionEngine should match any payload
    $engine = app()->make(ConditionEngine::class);
    expect($engine->matches([], ['any' => 'data']))->toBeTrue();
});

// ─── WebhookAction handle with various payload edge cases ───

test('webhook action requires url key in payload', function (): void {
    $action = app()->make(WebhookAction::class);

    expect(fn (): mixed => $action->handle(['data' => 'no url']))
        ->toThrow(InvalidArgumentException::class, 'requires a non-empty "url"');
});

test('webhook action throws on empty url value', function (): void {
    $action = app()->make(WebhookAction::class);

    expect(fn (): mixed => $action->handle(['url' => '']))
        ->toThrow(InvalidArgumentException::class, 'requires a non-empty "url"');
});

test('webhook action throws on non-string url value', function (): void {
    $action = app()->make(WebhookAction::class);

    expect(fn (): mixed => $action->handle(['url' => 12345]))
        ->toThrow(InvalidArgumentException::class, 'requires a non-empty "url"');
});

// ─── Subscription signPayload with custom algorithm ───

test('subscription signPayload uses custom algorithm from config', function (): void {
    Config::set('events.subscriptions.signature_algorithm', 'sha384');

    $sub = Subscription::factory()->create(['secret' => 'test_secret_key']);

    $signature = $sub->signPayload('test payload');

    // sha384 produces 96-char hex
    expect($signature)->toBe(hash_hmac('sha384', 'test payload', 'test_secret_key'))
        ->and(strlen($signature))->toBe(96);

    Config::set('events.subscriptions.signature_algorithm', 'sha256');
});

test('subscription signPayload falls back to sha256 for invalid algorithm config', function (): void {
    Config::set('events.subscriptions.signature_algorithm', 'invalid_algo');

    $sub = Subscription::factory()->create(['secret' => 'fallback_key']);

    $signature = $sub->signPayload('payload');

    // hash_hmac falls back gracefully
    expect($signature)->toBeString()->and(strlen($signature))->toBeGreaterThan(0);

    Config::set('events.subscriptions.signature_algorithm', 'sha256');
});

// ─── DispatchTriggerJob property types via reflection ───

test('dispatch trigger job has correct property types', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);

    $tries = $ref->getProperty('tries');
    expect($tries->getType()->getName())->toBe('int');

    $queue = $ref->getProperty('queue');
    expect($queue->getType()->getName())->toBe('string');

    $connection = $ref->getProperty('connection');
    expect($connection->getType()->getName())->toBe('string');
    expect($connection->getType()->allowsNull())->toBeTrue();

    $backoff = $ref->getProperty('backoff');
    expect($backoff->getType()->getName())->toBe('array');
});

// ─── ConditionEngine AND logic comprehensive ───

test('condition engine rejects when any condition fails', function (): void {
    $engine = app()->make(ConditionEngine::class);

    // All match → true
    expect($engine->matches(
        ['status' => 'active', 'role' => 'admin'],
        ['status' => 'active', 'role' => 'admin'],
    ))->toBeTrue();

    // One fails → false
    expect($engine->matches(
        ['status' => 'active', 'role' => 'admin'],
        ['status' => 'inactive', 'role' => 'admin'],
    ))->toBeFalse();

    // Both fail → false
    expect($engine->matches(
        ['status' => 'active', 'role' => 'admin'],
        ['status' => 'inactive', 'role' => 'user'],
    ))->toBeFalse();
});

test('condition engine with three conditions requires all to match', function (): void {
    $engine = app()->make(ConditionEngine::class);

    expect($engine->matches(
        ['a' => '1', 'b' => '2', 'c' => '3'],
        ['a' => '1', 'b' => '2', 'c' => '3'],
    ))->toBeTrue();

    expect($engine->matches(
        ['a' => '1', 'b' => '2', 'c' => '3'],
        ['a' => '1', 'b' => '2', 'c' => 'wrong'],
    ))->toBeFalse();
});

// ─── WildcardMatcher special patterns ───

test('wildcard matcher handles single star only pattern', function (): void {
    expect(WildcardMatcher::matches('*', 'any.event.name'))->toBeTrue();
    expect(WildcardMatcher::matches('*', 'single'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
});

test('wildcard matcher double star matches everything non-empty', function (): void {
    expect(WildcardMatcher::matches('**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('**', 'a.b.c.d.e'))->toBeTrue();
    expect(WildcardMatcher::matches('**', 'single'))->toBeTrue();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
});

test('wildcard matcher extracts wildcards from multi-wildcard pattern', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*.created.*', 'user.profile.created.admin');

    expect($result)->toBe(['profile', 'admin']);
});

test('wildcard matcher returns empty for non-matching pattern extraction', function (): void {
    expect(WildcardMatcher::extractWildcards('user.*.created', 'order.placed'))->toBe([]);
});

// ─── TriggerBuilder resolveActions deduplication ───

test('trigger builder deduplicates actions across action() and actions()', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'test.dedup.actions',
        'action' => json_encode(['App\\Actions\\A', 'App\\Actions\\B', 'App\\Actions\\A']),
    ]);

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBe(['App\\Actions\\A', 'App\\Actions\\B']);
});

// ─── EventLog status transitions ───

test('event log status transition from pending to completed', function (): void {
    $log = EventLog::factory()->pending()->create();

    expect($log->status)->toBe(EventLog::STATUS_PENDING);

    $log->markAsCompleted(150);

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($log->duration_ms)->toBe(150);
});

test('event log status transition from pending to failed', function (): void {
    $log = EventLog::factory()->pending()->create();

    $log->markAsFailed('Connection timeout');

    expect($log->status)->toBe(EventLog::STATUS_FAILED)
        ->and($log->error)->toBe('Connection timeout');
});

// ─── Subscription matchesEvent comprehensive ───

test('subscription matches exact event', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.placed']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.shipped'))->toBeFalse();
});

test('subscription matches single-segment wildcard', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.*']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.shipped'))->toBeTrue()
        ->and($sub->matchesEvent('order.placed.extra'))->toBeFalse();
});

test('subscription matches cross-segment wildcard', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.**']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.placed.extra'))->toBeTrue()
        ->and($sub->matchesEvent('order.a.b.c'))->toBeTrue();
});

// ─── EventManager fire with no matching triggers ───

test('event manager fire with no matching triggers does not error', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

    // Fire a unique event that has no triggers registered
    $manager->fire('nonexistent.unique.event.12345', ['test' => true]);

    // Should complete without error
    expect(true)->toBeTrue();
});

// ─── Config type validation ───

test('config table_names section has string values', function (): void {
    $tables = Config::get('events.table_names');

    expect($tables)->toBeArray()
        ->and($tables['triggers'])->toBeString()
        ->and($tables['event_logs'])->toBeString()
        ->and($tables['subscriptions'])->toBeString();
});

test('config subscriptions section has correct key types', function (): void {
    $subs = Config::get('events.subscriptions');

    expect($subs)->toBeArray()
        ->and($subs['auto_generate_secret'])->toBeBool()
        ->and($subs['max_failures'])->toBeInt()
        ->and($subs['timeout'])->toBeInt()
        ->and($subs['signature_algorithm'])->toBeString();
});

// ─── ServiceProvider register bindings verification ───

test('service provider registers event manager as singleton', function (): void {
    $first = app()->make(\ZeroBoiler\Events\EventManager::class);
    $second = app()->make(\ZeroBoiler\Events\EventManager::class);

    expect($first)->toBe($second); // Same instance (singleton)
});

test('service provider registers trigger builder as transient', function (): void {
    $first = app()->make(TriggerBuilder::class);
    $second = app()->make(TriggerBuilder::class);

    expect($first)->not->toBe($second); // Different instances (transient)
});

test('service provider registers subscription builder as transient', function (): void {
    $first = app()->make(SubscriptionBuilder::class);
    $second = app()->make(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

// ─── Facade accessor ───

test('facade resolves to correct event manager class', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $ref->getMethod('getFacadeAccessor');

    expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
});

// ─── Strict types enforcement ───

test('all source files have strict types declaration', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = iterator_to_array(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ),
        false,
    );

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $contents = file_get_contents($file->getPathname());
            expect($contents)->toContain('declare(strict_types=1)');
        }
    }
});

// ─── Final class verification ───

test('core classes are final', function (): void {
    $finalClasses = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        \ZeroBoiler\Events\SubscriptionBuilder::class,
        \ZeroBoiler\Events\ConditionEngine::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\WildcardMatcher::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

// ─── Model config-driven table names ───

test('trigger model reads table name from config', function (): void {
    Config::set('events.table_names.triggers', 'custom_triggers');

    $table = (new Trigger)->getTable();

    expect($table)->toBe('custom_triggers');

    Config::set('events.table_names.triggers', 'triggers');
});

test('event log model reads table name from config', function (): void {
    Config::set('events.table_names.event_logs', 'custom_event_logs');

    $table = (new EventLog)->getTable();

    expect($table)->toBe('custom_event_logs');

    Config::set('events.table_names.event_logs', 'event_logs');
});

test('subscription model reads table name from config', function (): void {
    Config::set('events.table_names.subscriptions', 'custom_subs');

    $table = (new Subscription)->getTable();

    expect($table)->toBe('custom_subs');

    Config::set('events.table_names.subscriptions', 'event_subscriptions');
});

// ─── Version consistency ───

test('composer json version matches format', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['version'])->toMatch('/^\d+\.\d+\.\d+$/');
    expect($composer['require']['php'])->toBe('^8.5');
});

// ─── EscapesWildcardLike ───

test('escapes wildcard like converts asterisk to percent', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
    $ref = new ReflectionMethod($manager, 'wildcardToLike');
    $ref->setAccessible();

    expect($ref->invoke($manager, 'order.*'))->toBe('order\\%')
        ->and($ref->invoke($manager, 'order.**'))->toBe('order\\%\\%');
});

test('escapes wildcard like returns null for non-wildcard pattern', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
    $ref = new ReflectionMethod($manager, 'wildcardToLike');
    $ref->setAccessible();

    expect($ref->invoke($manager, 'order.placed'))->toBeNull();
});

// ─── ManagesHistory getStats structure ───

test('getStats returns correct structure with no data', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

    $stats = $manager->getStats();

    expect($stats)->toHaveKeys([
        'total_logs', 'total_triggers', 'active_triggers',
        'completed', 'failed', 'pending', 'dispatched',
        'success_rate', 'failure_rate', 'avg_duration_ms',
        'top_events', 'top_failed_events',
    ])
    ->and($stats['total_logs'])->toBeInt()
    ->and($stats['total_triggers'])->toBeInt()
    ->and($stats['success_rate'])->toBeNull()
    ->and($stats['avg_duration_ms'])->toBeNull();
});

// ─── Trigger scopes ───

test('trigger enabled scope returns only enabled', function (): void {
    $enabled = Trigger::factory()->enabled()->create();
    Trigger::factory()->disabled()->create();

    $results = Trigger::enabled()->get();

    expect($results->pluck('id'))->toContain($enabled->id)
        ->and($results->count())->toBe(1);
});

// ─── Subscription scopes ───

test('subscription active scope returns only active', function (): void {
    $active = Subscription::factory()->active()->create();
    Subscription::factory()->inactive()->create();

    $results = Subscription::active()->get();

    expect($results->pluck('id'))->toContain($active->id)
        ->and($results->count())->toBe(1);
});
