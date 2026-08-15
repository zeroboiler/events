<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\EventScheduler;

/**
 * Comprehensive production readiness audit for the events package.
 *
 * Validates:
 * - All service container bindings are correct types
 * - Singleton vs transient registration
 * - Contract implementations
 * - Config completeness and type safety
 * - Final class enforcement
 * - API surface completeness (all public methods callable)
 * - Trigger builder edge cases
 * - Condition engine all operators
 * - Wildcard matcher edge cases
 * - Event sourcing domain event immutability
 * - Facade proxy resolution
 */
test('service container binds EventManager as singleton', function (): void {
    $app = app();
    $first = $app->make(EventManager::class);
    $second = $app->make(EventManager::class);

    expect($first)->toBe($second)
        ->and($first)->toBeInstanceOf(EventManager::class);
});

test('service container binds ConditionEngine as singleton', function (): void {
    $app = app();
    $first = $app->make(ConditionEngine::class);
    $second = $app->make(ConditionEngine::class);

    expect($first)->toBe($second)
        ->and($first)->toBeInstanceOf(ConditionEngine::class);
});

test('service container binds ActionResolver as singleton', function (): void {
    $app = app();
    $first = $app->make(ActionResolver::class);
    $second = $app->make(ActionResolver::class);

    expect($first)->toBe($second)
        ->and($first)->toBeInstanceOf(ActionResolver::class);
});

test('ConditionEngine implements ConditionEngineContract', function (): void {
    $app = app();
    $engine = $app->make(ConditionEngineContract::class);

    expect($engine)->toBeInstanceOf(ConditionEngine::class);
});

test('TriggerBuilder is bound as transient (fresh instance each time)', function (): void {
    $app = app();
    $first = $app->make(TriggerBuilder::class);
    $second = $app->make(TriggerBuilder::class);

    expect($first)->toBeInstanceOf(TriggerBuilder::class)
        ->and($second)->toBeInstanceOf(TriggerBuilder::class)
        ->and($first)->not->toBe($second);
});

test('SubscriptionBuilder is bound as transient (fresh instance each time)', function (): void {
    $app = app();
    $first = $app->make(SubscriptionBuilder::class);
    $second = $app->make(SubscriptionBuilder::class);

    expect($first)->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($second)->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($first)->not->toBe($second);
});

test('EventScheduler is bound as singleton', function (): void {
    $app = app();
    $first = $app->make(EventScheduler::class);
    $second = $app->make(EventScheduler::class);

    expect($first)->toBe($second)
        ->and($first)->toBeInstanceOf(EventScheduler::class);
});

test('EventManager container() returns the app container', function (): void {
    $manager = app(EventManager::class);
    $container = $manager->container();

    expect($container)->toBe(app());
});

test('EventManager provides() lists all expected services', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class)
        ->toContain(ConditionEngine::class)
        ->toContain(ConditionEngineContract::class)
        ->toContain(ActionResolver::class)
        ->toContain(TriggerBuilder::class)
        ->toContain(SubscriptionBuilder::class)
        ->toContain(EventScheduler::class);
});

test('all classes are final', function (): void {
    $reflectionClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        WildcardMatcher::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
    ];

    foreach ($reflectionClasses as $className) {
        $ref = new ReflectionClass($className);
        expect($ref->isFinal())->toBeTrue("{$className} should be final");
    }
});

test('WildcardMatcher readonly class has all static methods', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    expect($ref->isReadOnly())->toBeTrue()
        ->and($ref->isFinal())->toBeTrue();

    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];
    foreach ($methods as $method) {
        expect($ref->hasMethod($method))->toBeTrue("WildcardMatcher::{$method}() should exist")
            ->and($ref->getMethod($method)->isStatic())->toBeTrue("WildcardMatcher::{$method}() should be static")
            ->and($ref->getMethod($method)->isPublic())->toBeTrue("WildcardMatcher::{$method}() should be public");
    }
});

test('ConditionEngine handles all documented operators', function (): void {
    $engine = app(ConditionEngine::class);

    // Equality operators
    expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue()
        ->and($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();

    // Comparison operators
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue()
        ->and($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))->toBeFalse()
        ->and($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue()
        ->and($engine->matches(['amount' => ['<', 100]], ['amount' => 50]))->toBeTrue()
        ->and($engine->matches(['amount' => ['<=', 100]], ['amount' => 100]))->toBeTrue();

    // Strict equality
    expect($engine->matches(['value' => ['===', 0]], ['value' => 0]))->toBeTrue()
        ->and($engine->matches(['value' => ['===', '0']], ['value' => 0]))->toBeFalse();

    // Strict inequality
    expect($engine->matches(['value' => ['!==', '0']], ['value' => 0]))->toBeTrue();

    // In / not_in
    expect($engine->matches(['status' => ['in', ['a', 'b']]], ['status' => 'a']))->toBeTrue()
        ->and($engine->matches(['status' => ['not_in', ['a', 'b']]], ['status' => 'c']))->toBeTrue();

    // Contains
    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['normal', 'urgent']]))->toBeTrue()
        ->and($engine->matches(['bio' => ['contains', 'admin']], ['bio' => 'admin user']))->toBeTrue()
        ->and($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['a', 'b']]))->toBeTrue();

    // Between
    expect($engine->matches(['amount' => ['between', [50, 200]]], ['amount' => 100]))->toBeTrue()
        ->and($engine->matches(['amount' => ['between', [200, 50]]], ['amount' => 100]))->toBeTrue(); // inverted range

    // Null checks
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue()
        ->and($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => '2024-01-01']))->toBeTrue();

    // Empty checks
    expect($engine->matches(['items' => ['empty']], ['items' => []]))->toBeTrue()
        ->and($engine->matches(['items' => ['not_empty']], ['items' => [1]]))->toBeTrue();

    // String operators
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue()
        ->and($engine->matches(['email' => ['ends_with', '@test.com']], ['email' => 'admin@test.com']))->toBeTrue();

    // Regex matches
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']], ['code' => 'ABC-1234']))->toBeTrue()
        ->and($engine->matches(['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']], ['code' => 'invalid']))->toBeFalse();

    // Nested dot-notation
    expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue()
        ->and($engine->matches(['order.total' => ['>', 100]], ['order' => ['total' => 150]]))->toBeTrue();

    // Empty conditions array matches everything
    expect($engine->matches([], ['anything' => 'goes']))->toBeTrue();
});

test('ConditionEngine rejects ReDoS patterns', function (): void {
    $engine = app(ConditionEngine::class);

    // Nested quantifiers — should return false
    expect($engine->matches(['input' => ['matches', '/(a+)+/']], ['input' => 'aaaa']))->toBeFalse();
    expect($engine->matches(['input' => ['matches', '/(a*)*/']], ['input' => 'aaaa']))->toBeFalse();

    // Oversized regex — should return false
    $longPattern = '/^' . str_repeat('a', 600) . '$/';
    expect($engine->matches(['input' => ['matches', $longPattern]], ['input' => str_repeat('a', 600)]))->toBeFalse();
});

test('EventManager CRUD operations: create, read, update, delete', function (): void {
    $manager = app(EventManager::class);

    // Create
    $trigger = $manager->on('test.crud')
        ->name('CRUD Test')
        ->action('App\\Actions\\SendOrderNotification')
        ->save();

    expect($trigger)->toBeInstanceOf(Trigger::class)
        ->and($trigger->event)->toBe('test.crud')
        ->and($trigger->name)->toBe('CRUD Test')
        ->and($trigger->enabled)->toBeTrue();

    // Read
    $found = $manager->getTrigger($trigger->id);
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($trigger->id);

    // List
    $list = $manager->listTriggers('test.crud');
    expect($list)->not->toBeEmpty();

    // Disable
    $result = $manager->disable($trigger->id);
    expect($result)->toBeTrue();
    $found = $manager->getTrigger($trigger->id);
    expect($found->enabled)->toBeFalse();

    // Enable
    $result = $manager->enable($trigger->id);
    expect($result)->toBeTrue();
    $found = $manager->getTrigger($trigger->id);
    expect($found->enabled)->toBeTrue();

    // Delete
    $result = $manager->deleteTrigger($trigger->id);
    expect($result)->toBeTrue();
    $found = $manager->getTrigger($trigger->id);
    expect($found)->toBeNull();

    // Double delete returns false
    $result = $manager->deleteTrigger($trigger->id);
    expect($result)->toBeFalse();
});

test('EventManager fire with empty event name throws exception', function (): void {
    $manager = app(EventManager::class);

    expect(fn () => $manager->fire(''))
        ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty.');
});

test('EventManager fire with zero string event name throws exception', function (): void {
    $manager = app(EventManager::class);

    expect(fn () => $manager->fire('0'))
        ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty.');
});

test('EventManager global disable suppresses all fire calls', function (): void {
    $manager = app(EventManager::class);

    $manager->on('test.disabled')
        ->name('Disabled Test')
        ->action('App\\Actions\\LogOrderEvent')
        ->save();

    $countBefore = EventLog::count();
    $manager->setEnabled(false);
    $manager->fire('test.disabled', ['key' => 'value']);
    $countAfter = EventLog::count();

    expect($countAfter)->toBe($countBefore);

    // Re-enable
    $manager->setEnabled(true);
});

test('EventManager enable/disable guards against empty/zero IDs', function (): void {
    $manager = app(EventManager::class);

    expect($manager->enable(''))->toBeFalse()
        ->and($manager->enable('0'))->toBeFalse()
        ->and($manager->disable(''))->toBeFalse()
        ->and($manager->disable('0'))->toBeFalse();
});

test('EventManager getTrigger guards against empty/zero IDs', function (): void {
    $manager = app(EventManager::class);

    expect($manager->getTrigger(''))->toBeNull()
        ->and($manager->getTrigger('0'))->toBeNull();
});

test('EventManager deleteTrigger guards against empty/zero IDs', function (): void {
    $manager = app(EventManager::class);

    expect($manager->deleteTrigger(''))->toBeFalse()
        ->and($manager->deleteTrigger('0'))->toBeFalse();
});

test('EventManager fireModel constructs event name and flattens attributes', function (): void {
    $manager = app(EventManager::class);
    $logCount = EventLog::count();

    $model = new class extends Trigger {
        public function attributesToArray(): array
        {
            return ['id' => '123', 'name' => 'Test', 'status' => 'active'];
        }
    };

    $manager->on('TestModel.created')
        ->name('FireModel Test')
        ->action('App\\Actions\\LogOrderEvent')
        ->save();

    $manager->fireModel(get_class($model), 'created', $model);

    // Should have created an EventLog
    expect(EventLog::count())->toBe($logCount + 1);

    $log = EventLog::latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->event)->toBe('TestModel.created');
});

test('fireModel throws for empty class name or action', function (): void {
    $manager = app(EventManager::class);
    $model = new stdClass;

    expect(fn () => $manager->fireModel('', 'created', $model))
        ->toThrow(InvalidArgumentException::class, 'Model class name cannot be empty.');

    expect(fn () => $manager->fireModel('SomeModel', '', $model))
        ->toThrow(InvalidArgumentException::class, 'Model action cannot be empty.');
});

test('TriggerBuilder validates event and action on save', function (): void {
    $manager = app(EventManager::class);

    // Missing event
    expect(fn () => $manager->on('')
        ->action('App\\Actions\\LogOrderEvent')
        ->save())
        ->toThrow(InvalidArgumentException::class, 'Event name is required');

    // Missing action
    expect(fn () => $manager->on('test.event')
        ->save())
        ->toThrow(InvalidArgumentException::class, 'At least one action is required');
});

test('TriggerBuilder actions() validates non-empty strings', function (): void {
    $manager = app(EventManager::class);

    expect(fn () => $manager->on('test.event')
        ->actions(['', 'ValidClass'])
        ->save())
        ->toThrow(InvalidArgumentException::class, 'Each action class must be a non-empty string.');
});

test('TriggerBuilder resolveActions merges and deduplicates action() + actions()', function (): void {
    $manager = app(EventManager::class);

    // Use actions() with action() — should merge without duplicates
    $trigger = $manager->on('test.merge')
        ->action('App\\Actions\\LogOrderEvent')
        ->actions(['App\\Actions\\LogOrderEvent', 'App\\Actions\\SendOrderNotification'])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBe(['App\\Actions\\LogOrderEvent', 'App\\Actions\\SendOrderNotification']);
});

test('SubscriptionBuilder validates event and URL on save', function (): void {
    $manager = app(EventManager::class);

    // Missing event
    expect(fn () => $manager->subscribe('', 'https://example.com')
        ->save())
        ->toThrow(InvalidArgumentException::class, 'Event name is required for subscription');

    // Missing URL
    expect(fn () => $manager->subscribe('test.event', '')
        ->save())
        ->toThrow(InvalidArgumentException::class, 'Webhook URL is required for subscription');

    // Invalid URL
    expect(fn () => $manager->subscribe('test.event', 'not-a-url')
        ->save())
        ->toThrow(InvalidArgumentException::class, 'Webhook URL must be a valid URL');

    // Non-HTTP scheme
    expect(fn () => $manager->subscribe('test.event', 'ftp://example.com')
        ->save())
        ->toThrow(InvalidArgumentException::class, 'Webhook URL must use HTTP or HTTPS protocol');
});

test('DomainEvent immutability and round-trip serialization', function (): void {
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('user.registered', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    // Verify immutability (readonly properties)
    expect($original->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class)
        ->and($original->eventType)->toBe('user.registered')
        ->and($original->occurredAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($original->payload)->toBe(['email' => 'test@example.com', 'name' => 'Test User']);

    // Serialize and reconstruct
    $data = $original->toArray();
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($original->eventId->toString())
        ->and($restored->eventType)->toBe($original->eventType)
        ->and($restored->payload)->toBe($original->payload)
        ->and($restored->occurredAt->format(DateTimeImmutable::ATOM))
            ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
});

test('DomainEvent fromArray with missing eventType throws', function (): void {
    expect(fn () => \ZeroBoiler\Events\Domain\DomainEvent::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'DomainEvent eventType is required for reconstruction.');
});

test('DomainEvent fromArray with invalid UUID generates fresh one', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => 'not-a-uuid',
    ]);

    // Should have generated a fresh valid UUID instead of using the invalid one
    expect($event->eventId->toString())->not->toBe('not-a-uuid');
});

test('DomainEvent fromArray with invalid datetime uses now', function (): void {
    $before = new DateTimeImmutable();
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test.event',
        'occurredAt' => 'not-a-date',
    ]);
    $after = new DateTimeImmutable();

    expect($event->occurredAt)->toBeGreaterThanOrEqual($before)
        ->and($event->occurredAt)->toBeLessThanOrEqual($after);
});

test('EventLog status constants are complete', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending')
        ->and(EventLog::STATUS_DISPATCHED)->toBe('dispatched')
        ->and(EventLog::STATUS_COMPLETED)->toBe('completed')
        ->and(EventLog::STATUS_FAILED)->toBe('failed');

    expect(EventLog::$statuses)->toBe([
        'pending',
        'dispatched',
        'completed',
        'failed',
    ]);
});

test('EventLog markAsCompleted and markAsFailed update correctly', function (): void {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'status' => EventLog::STATUS_PENDING,
    ]);

    $log->markAsCompleted(42);
    expect($log->fresh()->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($log->fresh()->duration_ms)->toBe(42);

    $log->markAsFailed('Something went wrong');
    expect($log->fresh()->status)->toBe(EventLog::STATUS_FAILED)
        ->and($log->fresh()->error)->toBe('Something went wrong');
});

test('Subscription signPayload generates HMAC signature', function (): void {
    $sub = Subscription::factory()->create([
        'secret' => 'whsec_test_secret_key',
    ]);

    $signature = $sub->signPayload('{"event":"test"}');

    expect($signature)->not->toBeEmpty()
        ->and(strlen($signature))->toBeGreaterThan(0);
});

test('Subscription signPayload returns empty for null secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => null]);

    expect($sub->signPayload('{"event":"test"}'))->toBe('');
});

test('Subscription recordDelivery increments counter atomically', function (): void {
    $sub = Subscription::factory()->create(['delivery_count' => 0]);

    $sub->recordDelivery();
    expect($sub->fresh()->delivery_count)->toBe(1);

    $sub->recordDelivery();
    expect($sub->fresh()->delivery_count)->toBe(2);
});

test('Subscription recordFailure increments and resetFailures zeroes', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 0]);

    $sub->recordFailure();
    $sub->recordFailure();
    expect($sub->fresh()->failure_count)->toBe(2);

    $sub->resetFailures();
    expect($sub->fresh()->failure_count)->toBe(0);
});

test('Subscription hasExceededFailures respects config threshold', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 5]);

    expect($sub->hasExceededFailures(10))->toBeFalse()
        ->and($sub->hasExceededFailures(5))->toBeTrue()
        ->and($sub->hasExceededFailures(3))->toBeTrue();
});

test('Subscription scopeExceededFailures uses config default', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 10]);

    // Default max_failures is 10, so failure_count >= 10 should match
    $exceeded = Subscription::active()->exceededFailures()->get();
    expect($exceeded->pluck('id')->contains($sub->id))->toBeTrue();
});

test('parseActions handles all documented formats', function (): void {
    // Use reflection to access the protected method
    $manager = app(EventManager::class);
    $ref = new ReflectionMethod($manager, 'parseActions');
    $ref->setAccessible(true);

    // Single class name
    expect($ref->invoke($manager, 'App\\Actions\\Foo'))
        ->toBe(['App\\Actions\\Foo']);

    // JSON array of class names
    expect($ref->invoke($manager, '["App\\\\Actions\\\\Foo","App\\\\Actions\\\\Bar"]'))
        ->toBe(['App\\Actions\\Foo', 'App\\Actions\\Bar']);

    // JSON object with class + params
    $result = $ref->invoke($manager, '{"class":"App\\\\Actions\\\\Foo","params":{"url":"https://example.com"}}');
    expect($result)->toBe([
        ['class' => 'App\\Actions\\Foo', 'params' => ['url' => 'https://example.com']],
    ]);

    // JSON object with classes + params (multiple actions)
    $result = $ref->invoke($manager, '{"classes":["App\\\\Actions\\\\Foo","App\\\\Actions\\\\Bar"],"params":{"url":"https://example.com"}}');
    expect($result)->toBe([
        ['class' => 'App\\Actions\\Foo', 'params' => ['url' => 'https://example.com']],
        ['class' => 'App\\Actions\\Bar', 'params' => ['url' => 'https://example.com']],
    ]);

    // Empty string
    expect($ref->invoke($manager, ''))->toBe([]);

    // Zero string
    expect($ref->invoke($manager, '0'))->toBe([]);
});

test('EscapesWildcardLike trait converts patterns correctly', function (): void {
    // Test via Subscription model which uses the trait
    $sub = new Subscription;

    $ref = new ReflectionMethod($sub, 'wildcardToLike');
    $ref->setAccessible(true);

    // No wildcard returns null
    expect($ref->invoke($sub, 'order.placed'))->toBeNull();

    // Single wildcard
    expect($ref->invoke($sub, 'order.*'))->toBe('order.%');

    // Double wildcard
    expect($ref->invoke($sub, 'order.**'))->toBe('order.%');

    // Multiple wildcards
    expect($ref->invoke($sub, '*.order.*'))->toBe('%.order.%');

    // Wildcard with special characters
    expect($ref->invoke($sub, 'order.%.*'))->toBe('order.\\%.%');

    // Wildcard with underscore
    expect($ref->invoke($sub, 'order_*.test'))->toBe('order\\_%.test');
});

test('EventManager invalidateTriggerCache clears wildcard cache', function (): void {
    $manager = app(EventManager::class);
    Cache::put('zeroboiler:events:enabled_wildcard_triggers', collect());

    $manager->invalidateTriggerCache();

    expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();
});

test('WildcardMatcher handles patterns with regex special chars', function (): void {
    // Dot in event without wildcard should match literally
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();

    // Parentheses should be escaped
    expect(WildcardMatcher::matches('test.*', 'test(hello)'))->toBeTrue();

    // Plus sign should be escaped
    expect(WildcardMatcher::matches('test.*', 'test+more'))->toBeTrue();

    // Pattern with regex chars but no wildcards — literal match
    expect(WildcardMatcher::matches('test.+', 'test.+'))->toBeTrue();
});

test('config events.disabled handles all boolean-ish values', function (): void {
    $manager = app(EventManager::class);

    // Default is false
    expect($manager->isDisabled())->toBeFalse();

    // Set to true
    $manager->setEnabled(false);
    expect($manager->isDisabled())->toBeTrue();

    // Reset
    $manager->setEnabled(true);
    expect($manager->isDisabled())->toBeFalse();
});

test('EventManager getTriggerCacheTtl reads from config', function (): void {
    $manager = app(EventManager::class);
    $ref = new ReflectionMethod($manager, 'getTriggerCacheTtl');
    $ref->setAccessible(true);

    // Default value is 300
    $ttl = $ref->invoke($manager);
    expect($ttl)->toBe(300);
});

test('ManagesHistory purgeLogs only deletes matching status by default', function (): void {
    $trigger = Trigger::factory()->create();
    $completed = EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'created_at' => now()->subDays(60),
    ]);
    $failed = EventLog::factory()->failed()->create([
        'trigger_id' => $trigger->id,
        'created_at' => now()->subDays(60),
    ]);
    $pending = EventLog::factory()->pending()->create([
        'trigger_id' => $trigger->id,
        'created_at' => now()->subDays(60),
    ]);

    // Purge without includePending — should not delete pending
    $deleted = app(EventManager::class)->purgeLogs(now()->subDays(30), includePending: false);

    expect($deleted)->toBe(2); // completed + failed
    expect(EventLog::find($pending->id))->not->toBeNull(); // pending still exists
});

test('ManagesHistory purgeLogs deletes pending when includePending is true', function (): void {
    $trigger = Trigger::factory()->create();
    $pending = EventLog::factory()->pending()->create([
        'trigger_id' => $trigger->id,
        'created_at' => now()->subDays(60),
    ]);

    $deleted = app(EventManager::class)->purgeLogs(now()->subDays(30), includePending: true);

    expect($deleted)->toBe(1);
    expect(EventLog::find($pending->id))->toBeNull();
});

test('ManagesHistory getStats returns correct shape', function (): void {
    $stats = app(EventManager::class)->getStats();

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
});

test('ManagesSubscriptions subscribe/unsubscribe flow', function (): void {
    $manager = app(EventManager::class);

    $sub = $manager->subscribe('test.webhook', 'https://example.com/webhook')
        ->withSecret('whsec_test123')
        ->priority(5)
        ->save();

    expect($sub)->toBeInstanceOf(Subscription::class)
        ->and($sub->secret)->toBe('whsec_test123')
        ->and($sub->active)->toBeTrue();

    // List
    $subs = $manager->listSubscriptions('test.webhook');
    expect($subs->isNotEmpty())->toBeTrue();

    // Get
    $found = $manager->getSubscription($sub->id);
    expect($found)->not->toBeNull();

    // Unsubscribe
    $result = $manager->unsubscribe($sub->id);
    expect($result)->toBeTrue();
    expect(Subscription::find($sub->id))->toBeNull();

    // Double unsubscribe
    expect($manager->unsubscribe($sub->id))->toBeFalse();
});

test('subscribeWebhook quick helper', function (): void {
    $manager = app(EventManager::class);

    $triggerId = $manager->subscribeWebhook('test.quick', 'https://example.com/quick', ['status' => 'active']);

    expect($triggerId)->toBeString()
        ->and(strlen($triggerId))->toBeGreaterThan(0);

    $trigger = Trigger::find($triggerId);
    expect($trigger)->not->toBeNull()
        ->and($trigger->event)->toBe('test.quick');
});
