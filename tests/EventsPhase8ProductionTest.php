<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// Load test action classes

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
    Cache::flush();
});

// ── ConditionEngine: Deep Dot Notation ──────────────────────────────────────

test('condition engine evaluates triple-nested dot notation', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(
        ['user.profile.role' => 'admin'],
        ['user' => ['profile' => ['role' => 'admin']]],
    ))->toBeTrue()
        ->and($engine->matches(
            ['user.profile.role' => 'user'],
            ['user' => ['profile' => ['role' => 'admin']]],
        ))->toBeFalse();
});

test('condition engine handles null intermediate in nested path', function (): void {
    $engine = app(ConditionEngine::class);

    // user exists but profile is null — nested key should resolve to null
    expect($engine->matches(
        ['user.profile.role' => ['null']],
        ['user' => ['profile' => null]],
    ))->toBeTrue();

    // user exists but is not array — nested key should resolve to null
    expect($engine->matches(
        ['user.profile.name' => ['null']],
        ['user' => 'string_value'],
    ))->toBeTrue();
});

// ── WildcardMatcher: Comprehensive Edge Cases ───────────────────────────────

test('wildcard matcher handles backslash in event name', function (): void {
    expect(WildcardMatcher::matches('app.*', 'app.module\\handler'))
        ->toBeTrue();
});

test('wildcard matcher rejects empty pattern against non-empty event', function (): void {
    expect(WildcardMatcher::matches('', 'order.placed'))
        ->toBeFalse();
});

test('wildcard matcher findMatchingPatterns preserves order', function (): void {
    $patterns = ['z.last', 'a.first', 'm.middle'];
    $matched = WildcardMatcher::findMatchingPatterns($patterns, 'a.first');

    expect($matched)->toBe(['a.first']);
});

test('wildcard matcher extractWildcards returns empty for non-matching patterns', function (): void {
    expect(WildcardMatcher::extractWildcards('user.*.created', 'order.placed'))
        ->toBe([]);
});

// ── EventManager: Fire with Empty Payload ───────────────────────────────────

test('fire event with empty payload and no conditions matches', function (): void {
    Trigger::factory()->create([
        'event' => 'test.empty',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('test.empty');

    expect(EventLog::count())->toBe(1)
        ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and(EventLog::first()->payload)->toBe([]);
});

test('fire event with empty payload and empty conditions matches', function (): void {
    Trigger::factory()->create([
        'event' => 'test.empty.conditions',
        'action' => SendOrderNotification::class,
        'conditions' => [],
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('test.empty.conditions');

    expect(EventLog::count())->toBe(1);
});

// ── EventManager: Cache Invalidation on Multiple Operations ────────────────

test('cache invalidation works across enable disable and save', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.*',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Fire once to populate cache
    EventManagerFacade::fire('order.placed');
    expect(EventLog::count())->toBe(1);

    // Disable
    EventManagerFacade::disable($trigger->id);
    $trigger->refresh();
    expect($trigger->enabled)->toBeFalse();

    // Fire again — should not dispatch
    EventLog::query()->delete();
    EventManagerFacade::fire('order.placed');
    expect(EventLog::count())->toBe(0);

    // Enable
    EventManagerFacade::enable($trigger->id);
    $trigger->refresh();
    expect($trigger->enabled)->toBeTrue();

    // Fire again — should dispatch
    EventManagerFacade::fire('order.placed');
    expect(EventLog::count())->toBe(1);
});

// ── TriggerBuilder: Edge Cases ──────────────────────────────────────────────

test('trigger builder save with action params and multiple actions uses classes key', function (): void {
    $builder = app(TriggerBuilder::class);
    $trigger = $builder
        ->on('multi.params.test')
        ->actions([SendOrderNotification::class, LogOrderEvent::class])
        ->actionParams(['webhook_url' => 'https://example.com/hook'])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBeArray()
        ->toHaveKey('classes')
        ->toHaveKey('params')
        ->and($decoded['classes'])->toContain(SendOrderNotification::class)
        ->and($decoded['params']['webhook_url'])->toBe('https://example.com/hook');
});

test('trigger builder save with action params and single action uses class key', function (): void {
    $builder = app(TriggerBuilder::class);
    $trigger = $builder
        ->on('single.params.test')
        ->action(SendOrderNotification::class)
        ->actionParams(['webhook_url' => 'https://example.com/hook'])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBeArray()
        ->toHaveKey('class')
        ->toHaveKey('params')
        ->and($decoded['class'])->toBe(SendOrderNotification::class)
        ->and($decoded['params']['webhook_url'])->toBe('https://example.com/hook');
});

// ── Subscription: Scope and Model Coverage ──────────────────────────────────

test('subscription scopeForEvent with exact match', function (): void {
    Subscription::factory()->create([
        'event' => 'order.placed',
        'url' => 'https://example.com/hook',
    ]);

    Subscription::factory()->create([
        'event' => 'user.created',
        'url' => 'https://example.com/user',
    ]);

    $results = Subscription::forEvent('order.placed')->get();
    expect($results)->toHaveCount(1)
        ->and($results->first()->event)->toBe('order.placed');
});

test('subscription scopeForEvent with wildcard match includes wildcards', function (): void {
    Subscription::factory()->create([
        'event' => 'order.*',
        'url' => 'https://example.com/order-wildcard',
    ]);

    Subscription::factory()->create([
        'event' => 'order.placed',
        'url' => 'https://example.com/order-exact',
    ]);

    Subscription::factory()->create([
        'event' => 'user.created',
        'url' => 'https://example.com/user',
    ]);

    $results = Subscription::forEvent('order.placed')->get();
    expect($results)->toHaveCount(2);
});

test('subscription matchesEvent with cross-segment wildcard', function (): void {
    $sub = Subscription::factory()->create([
        'event' => 'order.**',
    ]);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.placed.item'))->toBeTrue()
        ->and($sub->matchesEvent('order.placed.item.shipped'))->toBeTrue()
        ->and($sub->matchesEvent('user.placed'))->toBeFalse();
});

test('subscription recordDelivery increments delivery count', function (): void {
    $sub = Subscription::factory()->create([
        'delivery_count' => 5,
    ]);

    $sub->recordDelivery();
    $sub->refresh();

    expect($sub->delivery_count)->toBe(6)
        ->and($sub->last_fired_at)->not->toBeNull();
});

test('subscription recordFailure increments failure count', function (): void {
    $sub = Subscription::factory()->create([
        'failure_count' => 3,
    ]);

    $sub->recordFailure();
    $sub->refresh();

    expect($sub->failure_count)->toBe(4);
});

test('subscription resetFailures sets count to zero', function (): void {
    $sub = Subscription::factory()->create([
        'failure_count' => 7,
    ]);

    $sub->resetFailures();
    $sub->refresh();

    expect($sub->failure_count)->toBe(0);
});

// ── DomainEvent: Additional Edge Cases ───────────────────────────────────────

test('domain event fromArray with extra keys ignores them gracefully', function (): void {
    $event = DomainEvent::fromArray([
        'eventId' => (string) \Ramsey\Uuid\Uuid::uuid4(),
        'eventType' => 'test.event',
        'payload' => ['key' => 'value'],
        'occurredAt' => '2025-01-01T00:00:00+00:00',
        'extra_key' => 'should_be_ignored',
        'another_extra' => 42,
    ]);

    $arr = $event->toArray();
    expect($arr)->not->toHaveKey('extra_key')
        ->and($arr)->not->toHaveKey('another_extra');
});

test('domain event fromArray with non-string eventType defaults to empty', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 12345,
    ]);

    expect($event->eventType)->toBe('');
});

test('domain event identity different events are not equal', function (): void {
    $event1 = DomainEvent::occur('user.created', ['id' => 1]);
    $event2 = DomainEvent::occur('user.created', ['id' => 1]);

    // Different UUIDs — not the same event
    expect($event1->eventId->toString)->not->toBe($event2->eventId->toString);
});

test('domain event toArray returns all expected keys', function (): void {
    $event = DomainEvent::occur('order.placed', ['order_id' => 123]);
    $arr = $event->toArray();

    expect($arr)->toHaveKeys([
        'eventId',
        'eventType',
        'payload',
        'occurredAt',
    ]);
});

// ── ServiceProvider: Contract Singleton Identity ─────────────────────────────

test('condition engine contract and concrete share singleton', function (): void {
    $contract = app(ConditionEngineContract::class);
    $concrete = app(ConditionEngine::class);

    expect($contract)->toBe($concrete);
});

// ── ActionResolver: Error Cases ──────────────────────────────────────────────

test('action resolver throws for non-existent class', function (): void {
    $resolver = app(ActionResolver::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('does not exist');

    $resolver->resolve('\ZeroBoiler\Events\Tests\Actions\NonExistentAction');
});

test('action resolver throws for class not implementing Triggerable', function (): void {
    $resolver = app(ActionResolver::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must implement');

    $resolver->resolve(\stdClass::class);
});

// ── Config: Validation ──────────────────────────────────────────────────────

test('all config keys exist with expected types', function (): void {
    $config = config('events');

    expect($config)->toBeArray()
        ->and($config['table_names'])->toBeArray()
        ->and($config['table_names']['triggers'])->toBeString()
        ->and($config['table_names']['event_logs'])->toBeString()
        ->and($config['table_names']['subscriptions'])->toBeString()
        ->and($config['queue'])->toBeArray()
        ->and($config['queue']['connection'])->toBeString()
        ->and($config['queue']['queue'])->toBeString()
        ->and($config['retry'])->toBeArray()
        ->and($config['retry']['tries'])->toBeInt()
        ->and($config['retry']['backoff'])->toBeString()
        ->and($config['retention'])->toBeArray()
        ->and($config['retention']['days'])->toBeInt()
        ->and($config['retention']['include_pending'])->toBeBool()
        ->and($config['subscriptions'])->toBeArray()
        ->and($config['subscriptions']['auto_generate_secret'])->toBeBool()
        ->and($config['subscriptions']['max_failures'])->toBeInt()
        ->and($config['subscriptions']['timeout'])->toBeInt()
        ->and($config['subscriptions']['signature_algorithm'])->toBeString()
        ->and($config['wildcard_cache_ttl'])->toBeInt();
});
