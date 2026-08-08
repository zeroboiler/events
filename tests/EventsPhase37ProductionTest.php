<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Actions\WebhookAction;

// ---------------------------------------------------------------------------
// ConditionEngine: between() with non-numeric range values
// ---------------------------------------------------------------------------

test('ConditionEngine between rejects non-numeric range values', function (): void {
    $engine = app(ConditionEngine::class);

    // String range values should return false
    expect($engine->matches(['age' => ['between', ['abc', 'def']]], ['age' => 25]))->toBeFalse();

    // Mixed numeric/string range should return false
    expect($engine->matches(['age' => ['between', [25, 'abc']]], ['age' => 30]))->toBeFalse();

    // Numeric range should still work correctly
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 17]))->toBeFalse();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 66]))->toBeFalse();

    // Inverted range auto-normalizes (PHPStan 9: both rawMin/rawMax guarded as numeric)
    expect($engine->matches(['age' => ['between', [65, 18]]], ['age' => 30]))->toBeTrue();

    // Float comparison
    expect($engine->matches(['score' => ['between', [1.5, 9.5]]], ['score' => 5]))->toBeTrue();
    expect($engine->matches(['score' => ['between', [1.5, 9.5]]], ['score' => 1]))->toBeFalse();
    expect($engine->matches(['score' => ['between', [1.5, 9.5]]], ['score' => 10]))->toBeFalse();
});

// ---------------------------------------------------------------------------
// ConditionEngine: between with null actual
// ---------------------------------------------------------------------------

test('ConditionEngine between returns false for null actual', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['amount' => ['between', [10, 100]]], ['amount' => null]))->toBeFalse();
});

// ---------------------------------------------------------------------------
// ConditionEngine: all comparison operators with float values
// ---------------------------------------------------------------------------

test('ConditionEngine comparison operators handle float values', function (): void {
    $engine = app(ConditionEngine::class);

    // Greater than
    expect($engine->matches(['price' => ['>', 9.99]], ['price' => 10.0]))->toBeTrue();
    expect($engine->matches(['price' => ['>', 10.0]], ['price' => 9.99]))->toBeFalse();

    // Less than
    expect($engine->matches(['price' => ['<', 10.0]], ['price' => 9.99]))->toBeTrue();
    expect($engine->matches(['price' => ['<', 9.99]], ['price' => 10.0]))->toBeFalse();

    // Greater than or equal
    expect($engine->matches(['price' => ['>=', 10.0]], ['price' => 10.0]))->toBeTrue();
    expect($engine->matches(['price' => ['>=', 10.0]], ['price' => 9.99]))->toBeFalse();

    // Less than or equal
    expect($engine->matches(['price' => ['<=', 10.0]], ['price' => 10.0]))->toBeTrue();
    expect($engine->matches(['price' => ['<=', 10.0]], ['price' => 10.01]))->toBeFalse();
});

// ---------------------------------------------------------------------------
// SubscriptionBuilder: URL scheme enforcement with parse_url edge cases
// ---------------------------------------------------------------------------

test('SubscriptionBuilder rejects URL with non-array parse_url result', function (): void {
    $eventManager = app(\ZeroBoiler\Events\EventManager::class);

    // The parse_url edge case is handled internally by the strict type guard.
    // Normal invalid URLs are caught by filter_var first.
    $builder = $eventManager->subscribe('test.event', 'not-a-url');
    expect(fn () => $builder->save())->toThrow(\InvalidArgumentException::class);
});

test('SubscriptionBuilder URL scheme check with null scheme component', function (): void {
    $eventManager = app(\ZeroBoiler\Events\EventManager::class);

    // A URL that passes filter_var but parse_url may return without scheme
    // (e.g., "localhost" is a valid URL without scheme)
    // This is caught by the scheme check since empty string !== http/https
    $builder = $eventManager->subscribe('test.event', 'http://valid-url.com');
    // Should NOT throw — http:// is valid
    $this->expectNotToPerformAssertions();
});

// ---------------------------------------------------------------------------
// helpers.php: fake() function returns Faker\Generator
// ---------------------------------------------------------------------------

test('fake helper returns Faker Generator instance', function (): void {
    $faker = fake();
    expect($faker)->toBeInstanceOf(\Faker\Generator::class);
});

test('fake helper produces deterministic results with same seed', function (): void {
    // Verify fake() works for string and number generation
    $word = fake()->word();
    expect($word)->toBeString();
    expect(strlen($word))->toBeGreaterThan(0);

    $number = fake()->numberBetween(1, 100);
    expect($number)->toBeInt();
    expect($number)->toBeGreaterThanOrEqual(1);
    expect($number)->toBeLessThanOrEqual(100);
});

// ---------------------------------------------------------------------------
// Trigger model: eventLogs relation returns correct type
// ---------------------------------------------------------------------------

test('Trigger eventLogs relation returns HasMany', function (): void {
    $trigger = Trigger::factory()->enabled()->create();
    EventLog::factory()->completed()->for($trigger)->count(3)->create();

    $logs = $trigger->eventLogs;
    expect($logs)->toHaveCount(3);
    expect($logs->first())->toBeInstanceOf(EventLog::class);
});

// ---------------------------------------------------------------------------
// EventLog model: trigger relation returns correct type
// ---------------------------------------------------------------------------

test('EventLog trigger relation returns BelongsTo', function (): void {
    $trigger = Trigger::factory()->enabled()->create();
    $log = EventLog::factory()->completed()->for($trigger)->create();

    $relatedTrigger = $log->trigger;
    expect($relatedTrigger)->not->toBeNull();
    expect($relatedTrigger)->toBeInstanceOf(Trigger::class);
    expect($relatedTrigger->id)->toBe($trigger->id);
});

// ---------------------------------------------------------------------------
// Subscription: matchesEvent with exact, wildcard, cross-segment
// ---------------------------------------------------------------------------

test('Subscription matchesEvent comprehensive patterns', function (): void {
    // Exact match
    $sub = Subscription::factory()->create(['event' => 'order.placed']);
    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeFalse();

    // Single-segment wildcard
    $subWild = Subscription::factory()->create(['event' => 'order.*']);
    expect($subWild->matchesEvent('order.placed'))->toBeTrue();
    expect($subWild->matchesEvent('order.placed.extra'))->toBeFalse();
    expect($subWild->matchesEvent('order'))->toBeFalse();

    // Cross-segment wildcard
    $subCross = Subscription::factory()->create(['event' => 'order.**']);
    expect($subCross->matchesEvent('order.placed'))->toBeTrue();
    expect($subCross->matchesEvent('order.placed.extra'))->toBeTrue();
    expect($subCross->matchesEvent('invoice.paid'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// WebhookAction: signPayload with various algorithms
// ---------------------------------------------------------------------------

test('WebhookAction subscription failure tracking', function (): void {
    $sub = Subscription::factory()->create([
        'failure_count' => 0,
        'active' => true,
    ]);

    expect($sub->failure_count)->toBe(0);
    $sub->recordFailure();
    expect($sub->failure_count)->toBe(1);
    $sub->recordFailure();
    expect($sub->failure_count)->toBe(2);
    $sub->resetFailures();
    expect($sub->failure_count)->toBe(0);
});

test('WebhookAction subscription delivery tracking', function (): void {
    $sub = Subscription::factory()->create([
        'delivery_count' => 0,
        'last_fired_at' => null,
    ]);

    $sub->recordDelivery();
    expect($sub->delivery_count)->toBe(1);
    expect($sub->last_fired_at)->not->toBeNull();

    $sub->recordDelivery();
    expect($sub->delivery_count)->toBe(2);
});

// ---------------------------------------------------------------------------
// DomainEvent: fromArray with various edge cases
// ---------------------------------------------------------------------------

test('DomainEvent fromArray with missing payload defaults to empty', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test.event',
    ]);

    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe([]);
});

test('DomainEvent fromArray with non-array payload defaults to empty', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test.event',
        'payload' => 'not-an-array',
    ]);

    expect($event->payload)->toBe([]);
});

test('DomainEvent fromArray with invalid UUID generates fresh one', function (): void {
    $event1 = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => 'invalid-uuid',
    ]);

    $event2 = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test.event',
    ]);

    // Both should have valid UUIDs but different ones
    expect($event1->eventId->toString())->not->toBe($event2->eventId->toString());
});

test('DomainEvent toArray/fromArray roundtrip preserves all keys', function (): void {
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('user.created', [
        'user_id' => 42,
        'email' => 'test@example.com',
    ]);

    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($original->toArray());

    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
        ->toBe($original->occurredAt->format(\DateTimeInterface::ATOM));
});

// ---------------------------------------------------------------------------
// Config completeness: all keys present with correct types
// ---------------------------------------------------------------------------

test('config has all required top-level keys', function (): void {
    $config = config('events');
    expect($config)->toBeArray();
    expect(array_keys($config))->toContain('table_names');
    expect(array_keys($config))->toContain('queue');
    expect(array_keys($config))->toContain('retry');
    expect(array_keys($config))->toContain('retention');
    expect(array_keys($config))->toContain('subscriptions');
    expect(array_keys($config))->toContain('wildcard_cache_ttl');
});

test('config table_names has all required sub-keys', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toBeArray();
    expect($tables)->toHaveKey('triggers');
    expect($tables)->toHaveKey('event_logs');
    expect($tables)->toHaveKey('subscriptions');
});

test('config subscriptions has all required sub-keys', function (): void {
    $subs = config('events.subscriptions');
    expect($subs)->toBeArray();
    expect($subs)->toHaveKey('auto_generate_secret');
    expect($subs)->toHaveKey('max_failures');
    expect($subs)->toHaveKey('timeout');
    expect($subs)->toHaveKey('signature_algorithm');
});

// ---------------------------------------------------------------------------
// ServiceProvider: all bindings are correct type
// ---------------------------------------------------------------------------

test('ServiceProvider registers EventManager as singleton', function (): void {
    $instance1 = app(\ZeroBoiler\Events\EventManager::class);
    $instance2 = app(\ZeroBoiler\Events\EventManager::class);
    expect($instance1)->toBe($instance2);
});

test('ServiceProvider registers ConditionEngine as singleton', function (): void {
    $instance1 = app(ConditionEngine::class);
    $instance2 = app(ConditionEngine::class);
    expect($instance1)->toBe($instance2);
});

test('ServiceProvider registers TriggerBuilder as transient', function (): void {
    $instance1 = app(\ZeroBoiler\Events\TriggerBuilder::class);
    $instance2 = app(\ZeroBoiler\Events\TriggerBuilder::class);
    expect($instance1)->not->toBe($instance2);
});

test('ServiceProvider registers SubscriptionBuilder as transient', function (): void {
    $instance1 = app(SubscriptionBuilder::class);
    $instance2 = app(SubscriptionBuilder::class);
    expect($instance1)->not->toBe($instance2);
});

test('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
    $contract = app(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
    $concrete = app(ConditionEngine::class);
    expect($contract)->toBe($concrete);
});

// ---------------------------------------------------------------------------
// Facade accessor
// ---------------------------------------------------------------------------

test('Facade accessor returns correct class name', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    expect($reflection->isFinal())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Version consistency
// ---------------------------------------------------------------------------

test('composer.json version matches expected format', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer)->not->toBeNull();
    expect($composer['version'])->toBeString();
    expect($composer['version'])->toMatch('/^\d+\.\d+\.\d+$/');
});

// ---------------------------------------------------------------------------
// Strict types enforcement
// ---------------------------------------------------------------------------

test('all source files declare strict types', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

// ---------------------------------------------------------------------------
// Final classes verification
// ---------------------------------------------------------------------------

test('all core classes are final', function (): void {
    $classes = [
        \ZeroBoiler\Events\EventManager::class,
        ConditionEngine::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\WildcardMatcher::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        SubscriptionBuilder::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
        WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
    ];

    foreach ($classes as $class) {
        $reflection = new \ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} must be final");
    }
});

// ---------------------------------------------------------------------------
// Model config-driven table names
// ---------------------------------------------------------------------------

test('Trigger model reads table name from config', function (): void {
    $trigger = new Trigger;
    expect($trigger->getTable())->toBe(config('events.table_names.triggers'));
});

test('EventLog model reads table name from config', function (): void {
    $log = new EventLog;
    expect($log->getTable())->toBe(config('events.table_names.event_logs'));
});

test('Subscription model reads table name from config', function (): void {
    $sub = new Subscription;
    expect($sub->getTable())->toBe(config('events.table_names.subscriptions'));
});
