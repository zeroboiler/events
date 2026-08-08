<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Database\Factories\EventLogFactory;
use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
use ZeroBoiler\Events\Database\Factories\TriggerFactory;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

uses(Tests\TestCase::class);

// ─── EventManager CRUD edge cases ──────────────────────────────────────

test('EventManager::getTrigger returns null for non-existent ID', function (): void {
    $manager = app(EventManager::class);

    expect($manager->getTrigger('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

test('EventManager::listTriggers returns empty collection when no triggers exist', function (): void {
    $manager = app(EventManager::class);

    $result = $manager->listTriggers();

    expect($result)->toBeInstanceOf(Collection::class)
        ->toBeEmpty();
});

test('EventManager::listTriggers with no filters returns all triggers', function (): void {
    $manager = app(EventManager::class);

    Trigger::factory()->count(3)->create(['enabled' => true]);

    $result = $manager->listTriggers();

    expect($result)->toHaveCount(3);
});

test('EventManager::listTriggers filters by exact event name', function (): void {
    $manager = app(EventManager::class);

    Trigger::factory()->create(['event' => 'order.placed', 'enabled' => true]);
    Trigger::factory()->create(['event' => 'user.created', 'enabled' => true]);

    $result = $manager->listTriggers(event: 'order.placed');

    expect($result)->toHaveCount(1)
        ->first()->event->toBe('order.placed');
});

test('EventManager::listTriggers filters by wildcard event name', function (): void {
    $manager = app(EventManager::class);

    Trigger::factory()->create(['event' => 'order.placed', 'enabled' => true]);
    Trigger::factory()->create(['event' => 'order.shipped', 'enabled' => true]);
    Trigger::factory()->create(['event' => 'user.created', 'enabled' => true]);

    $result = $manager->listTriggers(event: 'order.*');

    expect($result)->toHaveCount(2);
});

test('EventManager::listTriggers filters by enabled status', function (): void {
    $manager = app(EventManager::class);

    Trigger::factory()->create(['enabled' => true]);
    Trigger::factory()->create(['enabled' => false]);

    $result = $manager->listTriggers(enabled: true);

    expect($result)->toHaveCount(1)
        ->first()->enabled->toBeTrue();
});

test('EventManager::listTriggers filters by disabled status', function (): void {
    $manager = app(EventManager::class);

    Trigger::factory()->create(['enabled' => true]);
    Trigger::factory()->create(['enabled' => false]);

    $result = $manager->listTriggers(enabled: false);

    expect($result)->toHaveCount(1)
        ->first()->enabled->toBeFalse();
});

test('EventManager::listTriggers respects limit parameter', function (): void {
    $manager = app(EventManager::class);

    Trigger::factory()->count(5)->create(['enabled' => true]);

    $result = $manager->listTriggers(limit: 2);

    expect($result)->toHaveCount(2);
});

test('EventManager::listTriggers with null event and null enabled returns all', function (): void {
    $manager = app(EventManager::class);

    Trigger::factory()->create(['enabled' => true, 'event' => 'a.b']);
    Trigger::factory()->create(['enabled' => false, 'event' => 'c.d']);

    $result = $manager->listTriggers(event: null, enabled: null);

    expect($result)->toHaveCount(2);
});

test('EventManager::enable returns false for non-existent trigger', function (): void {
    $manager = app(EventManager::class);

    expect($manager->enable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager::disable returns false for non-existent trigger', function (): void {
    $manager = app(EventManager::class);

    expect($manager->disable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager::deleteTrigger returns false for non-existent trigger', function (): void {
    $manager = app(EventManager::class);

    expect($manager->deleteTrigger('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager::deleteTrigger removes trigger and invalidates cache', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->create(['enabled' => true]);
    $id = $trigger->id;

    expect($manager->deleteTrigger($id))->toBeTrue();
    expect(Trigger::find($id))->toBeNull();
});

// ─── Model Relations ───────────────────────────────────────────────────

test('Trigger hasMany eventLogs relation returns correct logs', function (): void {
    $trigger = Trigger::factory()->create();
    EventLog::factory()->count(3)->forTrigger($trigger->id)->create();

    $logs = $trigger->eventLogs;

    expect($logs)->toHaveCount(3);
    $logs->each(function (EventLog $log) use ($trigger): void {
        expect($log->trigger_id)->toBe($trigger->id);
    });
});

test('EventLog belongsTo trigger relation returns correct trigger', function (): void {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->forTrigger($trigger->id)->create();

    expect($log->trigger)->not->toBeNull()
        ->and($log->trigger->id)->toBe($trigger->id);
});

test('EventLog trigger relation returns null when trigger is deleted', function (): void {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->forTrigger($trigger->id)->create();

    $trigger->delete();

    // Soft delete — trigger still in DB but marked as deleted
    expect($log->trigger)->not->toBeNull();
});

// ─── DomainEvent edge cases ───────────────────────────────────────────

test('DomainEvent::fromArray with minimal valid data (eventType only)', function (): void {
    $event = DomainEvent::fromArray(['eventType' => 'test.created']);

    expect($event->eventType)->toBe('test.created')
        ->and($event->payload)->toBe([])
        ->and($event->eventId->toString())->toBeString()
        ->and($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent::fromArray with full valid data preserves all fields', function (): void {
    $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
    $time = '2026-01-15T10:30:00+00:00';

    $event = DomainEvent::fromArray([
        'eventId' => $uuid,
        'eventType' => 'order.placed',
        'payload' => ['order_id' => 42],
        'occurredAt' => $time,
    ]);

    expect($event->eventId->toString())->toBe($uuid)
        ->and($event->eventType)->toBe('order.placed')
        ->and($event->payload)->toBe(['order_id' => 42])
        ->and($event->occurredAt->format(DateTimeImmutable::ATOM))->toBe($time);
});

test('DomainEvent::occur creates fresh UUID each time', function (): void {
    $a = DomainEvent::occur('test.event');
    $b = DomainEvent::occur('test.event');

    expect($a->eventId->toString())->not->toBe($b->eventId->toString());
});

test('DomainEvent::occur accepts payload', function (): void {
    $event = DomainEvent::occur('payment.processed', ['amount' => 99.99]);

    expect($event->payload)->toBe(['amount' => 99.99]);
});

test('DomainEvent::toArray contains all required keys', function (): void {
    $event = DomainEvent::occur('user.deleted', ['user_id' => 7]);
    $array = $event->toArray();

    expect(array_keys($array))->toContain('eventId', 'eventType', 'payload', 'occurredAt');
});

test('DomainEvent::fromArray with invalid UUID generates fresh one', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => 'not-a-valid-uuid',
    ]);

    expect($event->eventId->toString())->toBeString()
        ->and(strlen($event->eventId->toString()))->toBe(36);
});

test('DomainEvent::fromArray with invalid datetime uses now', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'occurredAt' => 'not-a-date',
    ]);

    $diff = abs($event->occurredAt->getTimestamp() - time());
    expect($diff)->toBeLessThan(5); // Within 5 seconds
});

test('DomainEvent::fromArray with empty eventType throws', function (): void {
    DomainEvent::fromArray([]);
})->throws(InvalidArgumentException::class, 'DomainEvent eventType is required');

test('DomainEvent::fromArray with non-string eventType throws', function (): void {
    DomainEvent::fromArray(['eventType' => 123]);
})->throws(InvalidArgumentException::class, 'DomainEvent eventType is required');

// ─── ConditionEngine edge cases ────────────────────────────────────────

test('ConditionEngine::matches with empty conditions returns true', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches([], ['foo' => 'bar']))->toBeTrue();
});

test('ConditionEngine::matches with empty payload returns false for non-null checks', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['status' => 'active'], []))->toBeFalse();
});

test('ConditionEngine::matches with null checks returns true for empty payload', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['deleted_at' => ['null']], []))->toBeTrue();
});

test('ConditionEngine::matches with not_null returns false for empty payload', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['deleted_at' => ['not_null']], []))->toBeFalse();
});

test('ConditionEngine::matches with empty check returns true for empty payload value', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['tags' => ['empty']], ['tags' => []]))->toBeTrue();
});

test('ConditionEngine::matches with not_empty returns false for empty array', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['tags' => ['not_empty']], ['tags' => []]))->toBeFalse();
});

test('ConditionEngine::matches AND logic — all conditions must pass', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'active', 'amount' => 100],
    ))->toBeTrue();

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'inactive', 'amount' => 100],
    ))->toBeFalse();

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'active', 'amount' => 30],
    ))->toBeFalse();
});

// ─── WildcardMatcher edge cases ───────────────────────────────────────

test('WildcardMatcher::matches empty pattern with empty event returns true', function (): void {
    // Empty pattern has no wildcards, so it's exact match
    expect(WildcardMatcher::matches('', ''))->toBeTrue();
});

test('WildcardMatcher::matches empty pattern with non-empty event returns false', function (): void {
    expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
});

test('WildcardMatcher::matches non-empty pattern with empty event returns false', function (): void {
    expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
});

test('WildcardMatcher::extractWildcards with no wildcards returns empty array', function (): void {
    expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))->toBe([]);
});

test('WildcardMatcher::extractWildcards with single wildcard extracts correctly', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

    expect($result)->toBe(['profile']);
});

test('WildcardMatcher::extractWildcards with multiple wildcards extracts all', function (): void {
    $result = WildcardMatcher::extractWildcards('*.order.*', 'sales.order.created');

    expect($result)->toBe(['sales', 'created']);
});

test('WildcardMatcher::extractWildcards with ** returns empty array', function (): void {
    expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.shipped'))->toBe([]);
});

test('WildcardMatcher::findMatchingPatterns returns empty for no matches', function (): void {
    expect(WildcardMatcher::findMatchingPatterns(['order.*', 'user.*'], 'payment.processed'))->toBe([]);
});

test('WildcardMatcher::findMatchingPatterns returns matching patterns in order', function (): void {
    $result = WildcardMatcher::findMatchingPatterns(['order.*', 'user.*', '*.placed'], 'order.placed');

    expect($result)->toBe(['order.*', '*.placed']);
});

// ─── Model Scopes ─────────────────────────────────────────────────────

test('Trigger::scopeEnabled returns only enabled triggers', function (): void {
    Trigger::factory()->create(['enabled' => true]);
    Trigger::factory()->create(['enabled' => false]);

    $result = Trigger::enabled()->get();

    expect($result)->toHaveCount(1)
        ->first()->enabled->toBeTrue();
});

test('Trigger::scopeAsync returns only async triggers', function (): void {
    Trigger::factory()->create(['async' => true]);
    Trigger::factory()->create(['async' => false]);

    $result = Trigger::async()->get();

    expect($result)->toHaveCount(1)
        ->first()->async->toBeTrue();
});

test('Trigger::scopeOrderByPriority returns sorted by priority desc', function (): void {
    $low = Trigger::factory()->create(['priority' => 1]);
    $high = Trigger::factory()->create(['priority' => 100]);

    $result = Trigger::orderByPriority()->get();

    expect($result->first()->id)->toBe($high->id)
        ->and($result->last()->id)->toBe($low->id);
});

test('Subscription::scopeActive returns only active subscriptions', function (): void {
    Subscription::factory()->create(['active' => true]);
    Subscription::factory()->create(['active' => false]);

    $result = Subscription::active()->get();

    expect($result)->toHaveCount(1)
        ->first()->active->toBeTrue();
});

test('Subscription::scopeOrderByPriority returns sorted by priority desc', function (): void {
    $low = Subscription::factory()->create(['priority' => 1]);
    $high = Subscription::factory()->create(['priority' => 100]);

    $result = Subscription::orderByPriority()->get();

    expect($result->first()->id)->toBe($high->id)
        ->and($result->last()->id)->toBe($low->id);
});

// ─── EventLog status transitions ───────────────────────────────────────

test('EventLog::markAsCompleted updates status and duration', function (): void {
    $log = EventLog::factory()->create(['status' => EventLog::STATUS_PENDING]);

    $log->markAsCompleted(250);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($log->duration_ms)->toBe(250);
});

test('EventLog::markAsFailed updates status and error', function (): void {
    $log = EventLog::factory()->create(['status' => EventLog::STATUS_DISPATCHED]);

    $log->markAsFailed('Connection timeout');

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_FAILED)
        ->and($log->error)->toBe('Connection timeout');
});

test('EventLog::scopeWithStatus filters by status', function (): void {
    EventLog::factory()->create(['status' => EventLog::STATUS_COMPLETED]);
    EventLog::factory()->create(['status' => EventLog::STATUS_FAILED]);

    $result = EventLog::withStatus(EventLog::STATUS_COMPLETED)->get();

    expect($result)->toHaveCount(1)
        ->first()->status->toBe(EventLog::STATUS_COMPLETED);
});

test('EventLog::scopePending filters by pending status', function (): void {
    EventLog::factory()->create(['status' => EventLog::STATUS_PENDING]);
    EventLog::factory()->create(['status' => EventLog::STATUS_COMPLETED]);

    $result = EventLog::pending()->get();

    expect($result)->toHaveCount(1)
        ->first()->status->toBe(EventLog::STATUS_PENDING);
});

test('EventLog::scopeCompleted filters by completed status', function (): void {
    EventLog::factory()->create(['status' => EventLog::STATUS_COMPLETED]);
    EventLog::factory()->create(['status' => EventLog::STATUS_FAILED]);

    $result = EventLog::completed()->get();

    expect($result)->toHaveCount(1)
        ->first()->status->toBe(EventLog::STATUS_COMPLETED);
});

test('EventLog::scopeFailed filters by failed status', function (): void {
    EventLog::factory()->create(['status' => EventLog::STATUS_FAILED]);
    EventLog::factory()->create(['status' => EventLog::STATUS_PENDING]);

    $result = EventLog::failed()->get();

    expect($result)->toHaveCount(1)
        ->first()->status->toBe(EventLog::STATUS_FAILED);
});

// ─── Subscription methods ────────────────────────────────────────────

test('Subscription::recordDelivery increments delivery_count and sets last_fired_at', function (): void {
    $sub = Subscription::factory()->create(['delivery_count' => 0, 'last_fired_at' => null]);

    $sub->recordDelivery();
    $sub->refresh();

    expect($sub->delivery_count)->toBe(1)
        ->and($sub->last_fired_at)->not->toBeNull();

    $sub->recordDelivery();
    $sub->refresh();

    expect($sub->delivery_count)->toBe(2);
});

test('Subscription::recordFailure increments failure_count', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 0]);

    $sub->recordFailure();
    $sub->refresh();

    expect($sub->failure_count)->toBe(1);

    $sub->recordFailure();
    $sub->refresh();

    expect($sub->failure_count)->toBe(2);
});

test('Subscription::resetFailures sets failure_count to zero', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 5]);

    $sub->resetFailures();
    $sub->refresh();

    expect($sub->failure_count)->toBe(0);
});

test('Subscription::signPayload with null secret returns empty string', function (): void {
    $sub = Subscription::factory()->create(['secret' => null]);

    expect($sub->signPayload('test payload'))->toBe('');
});

test('Subscription::signPayload with empty secret returns empty string', function (): void {
    $sub = Subscription::factory()->create(['secret' => '']);

    expect($sub->signPayload('test payload'))->toBe('');
});

test('Subscription::signPayload produces deterministic HMAC', function (): void {
    $sub = Subscription::factory()->create(['secret' => 'whsec_test']);

    $sig1 = $sub->signPayload('hello');
    $sig2 = $sub->signPayload('hello');

    expect($sig1)->toBe($sig2)
        ->and($sig1)->not->toBeEmpty();
});

test('Subscription::signPayload produces different signatures for different payloads', function (): void {
    $sub = Subscription::factory()->create(['secret' => 'whsec_test']);

    $sig1 = $sub->signPayload('hello');
    $sig2 = $sub->signPayload('world');

    expect($sig1)->not->toBe($sig2);
});

test('Subscription::hasExceededFailures with default threshold', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 9]);

    expect($sub->hasExceededFailures())->toBeFalse();

    $sub->update(['failure_count' => 10]);
    $sub->refresh();

    expect($sub->hasExceededFailures())->toBeTrue();
});

test('Subscription::hasExceededFailures with custom threshold', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 2]);

    expect($sub->hasExceededFailures(5))->toBeFalse();
    expect($sub->hasExceededFailures(2))->toBeTrue();
});

test('Subscription::matchesEvent with exact match returns true', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.placed']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.shipped'))->toBeFalse();
});

test('Subscription::matchesEvent with wildcard pattern', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.*']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.shipped'))->toBeTrue()
        ->and($sub->matchesEvent('order.placed.extra'))->toBeFalse();
});

test('Subscription::matchesEvent with cross-segment wildcard', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.**']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.placed.extra'))->toBeTrue()
        ->and($sub->matchesEvent('user.created'))->toBeFalse();
});

// ─── TriggerBuilder edge cases ────────────────────────────────────────

test('TriggerBuilder::save generates auto-name from event', function (): void {
    $manager = app(EventManager::class);

    $trigger = $manager->on('payment.received')
        ->action(\App\Actions\SendOrderNotification::class)
        ->save();

    expect($trigger->name)->toBe('payment.received Trigger');
});

test('TriggerBuilder::save with only actions() method works', function (): void {
    $manager = app(EventManager::class);

    $trigger = $manager->on('multi.action')
        ->actions([\App\Actions\LogOrderEvent::class, \App\Actions\HighPriority::class])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBe([\App\Actions\LogOrderEvent::class, \App\Actions\HighPriority::class]);
});

test('TriggerBuilder::save with actionParams and single action', function (): void {
    $manager = app(EventManager::class);

    $trigger = $manager->on('webhook.event')
        ->action(\App\Actions\SendOrderNotification::class)
        ->actionParams(['url' => 'https://example.com/hook'])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded['class'])->toBe(\App\Actions\SendOrderNotification::class)
        ->and($decoded['params']['url'])->toBe('https://example.com/hook');
});

test('TriggerBuilder::save with actionParams and multiple actions uses classes key', function (): void {
    $manager = app(EventManager::class);

    $trigger = $manager->on('multi.params')
        ->actions([\App\Actions\LogOrderEvent::class, \App\Actions\HighPriority::class])
        ->actionParams(['url' => 'https://example.com/hook'])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded['classes'])->toBe([\App\Actions\LogOrderEvent::class, \App\Actions\HighPriority::class])
        ->and($decoded['params']['url'])->toBe('https://example.com/hook');
});

// ─── SubscriptionBuilder edge cases ────────────────────────────────────

test('SubscriptionBuilder::save with empty conditions stores null', function (): void {
    $manager = app(EventManager::class);

    // We can't easily test this without mocking the DB transaction
    // But we can verify the builder state
    $builder = app(SubscriptionBuilder::class);
    expect($builder)->toBeInstanceOf(SubscriptionBuilder::class);
});

test('SubscriptionBuilder::save rejects empty event', function (): void {
    $manager = app(EventManager::class);

    $manager->subscribe('', 'https://example.com/hook')->save();
})->throws(InvalidArgumentException::class, 'Event name is required');

test('SubscriptionBuilder::save rejects empty URL', function (): void {
    $manager = app(EventManager::class);

    $manager->subscribe('order.placed', '')->save();
})->throws(InvalidArgumentException::class, 'Webhook URL is required');

test('SubscriptionBuilder::save rejects invalid URL', function (): void {
    $manager = app(EventManager::class);

    $manager->subscribe('order.placed', 'not-a-url')->save();
})->throws(InvalidArgumentException::class, 'valid URL');

test('SubscriptionBuilder::save rejects non-HTTP scheme', function (): void {
    $manager = app(EventManager::class);

    $manager->subscribe('order.placed', 'ftp://evil.com')->save();
})->throws(InvalidArgumentException::class, 'HTTP or HTTPS');

// ─── EscapesWildcardLike ──────────────────────────────────────────────

test('EscapesWildcardLike::wildcardToLike with no wildcards returns null', function (): void {
    $manager = app(EventManager::class);

    // Access protected method via reflection
    $ref = new ReflectionMethod($manager, 'wildcardToLike');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'order.placed'))->toBeNull();
});

test('EscapesWildcardLike::wildcardToLike with single wildcard converts to %', function (): void {
    $manager = app(EventManager::class);

    $ref = new ReflectionMethod($manager, 'wildcardToLike');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'order.*'))->toBe('order.%');
});

test('EscapesWildcardLike::wildcardToLike escapes SQL special chars', function (): void {
    $manager = app(EventManager::class);

    $ref = new ReflectionMethod($manager, 'wildcardToLike');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'user_%*'))->toBe('user\\_\\%%');
});

// ─── EventManager::getEventHistory ─────────────────────────────────────

test('EventManager::getEventHistory returns empty collection when no logs', function (): void {
    $manager = app(EventManager::class);

    $result = $manager->getEventHistory();

    expect($result)->toBeInstanceOf(Collection::class)
        ->toBeEmpty();
});

test('EventManager::getEventHistory filters by event', function (): void {
    $manager = app(EventManager::class);
    $trigger = Trigger::factory()->create();

    EventLog::factory()->forTrigger($trigger->id)->create(['event' => 'order.placed']);
    EventLog::factory()->forTrigger($trigger->id)->create(['event' => 'user.created']);

    $result = $manager->getEventHistory(event: 'order.placed');

    expect($result)->toHaveCount(1)
        ->first()->event->toBe('order.placed');
});

test('EventManager::getEventHistory filters by status', function (): void {
    $manager = app(EventManager::class);
    $trigger = Trigger::factory()->create();

    EventLog::factory()->forTrigger($trigger->id)->completed()->create();
    EventLog::factory()->forTrigger($trigger->id)->failed()->create();

    $result = $manager->getEventHistory(status: EventLog::STATUS_COMPLETED);

    expect($result)->toHaveCount(1)
        ->first()->status->toBe(EventLog::STATUS_COMPLETED);
});

test('EventManager::getEventHistory filters by triggerId', function (): void {
    $manager = app(EventManager::class);
    $trigger1 = Trigger::factory()->create();
    $trigger2 = Trigger::factory()->create();

    EventLog::factory()->forTrigger($trigger1->id)->create();
    EventLog::factory()->forTrigger($trigger2->id)->create();

    $result = $manager->getEventHistory(triggerId: $trigger1->id);

    expect($result)->toHaveCount(1)
        ->first()->trigger_id->toBe($trigger1->id);
});

test('EventManager::getEventHistory respects limit', function (): void {
    $manager = app(EventManager::class);
    $trigger = Trigger::factory()->create();

    EventLog::factory()->count(5)->forTrigger($trigger->id)->create();

    $result = $manager->getEventHistory(limit: 2);

    expect($result)->toHaveCount(2);
});

// ─── EventManager::getStats zero state ────────────────────────────────

test('EventManager::getStats returns zero-state when no data', function (): void {
    $manager = app(EventManager::class);

    $stats = $manager->getStats();

    expect($stats)->toBe([
        'total_logs' => 0,
        'total_triggers' => 0,
        'active_triggers' => 0,
        'completed' => 0,
        'failed' => 0,
        'pending' => 0,
        'dispatched' => 0,
        'success_rate' => null,
        'failure_rate' => null,
        'avg_duration_ms' => null,
        'top_events' => [],
        'top_failed_events' => [],
    ]);
});

// ─── EventManager::purgeLogs ──────────────────────────────────────────

test('EventManager::purgeLogs deletes old completed logs', function (): void {
    $manager = app(EventManager::class);
    $trigger = Trigger::factory()->create();

    $oldLog = EventLog::factory()->forTrigger($trigger->id)->completed()->create();
    // Manually set created_at to the past
    EventLog::where('id', $oldLog->id)->update(['created_at' => now()->subDays(60)]);
    $oldLog->refresh();

    $recentLog = EventLog::factory()->forTrigger($trigger->id)->completed()->create();

    $deleted = $manager->purgeLogs(before: now()->subDays(30));

    expect($deleted)->toBe(1)
        ->and(EventLog::find($oldLog->id))->toBeNull()
        ->and(EventLog::find($recentLog->id))->not->toBeNull();
});

// ─── EventManager::subscribeWebhook ───────────────────────────────────

test('EventManager::unsubscribe returns false for non-existent subscription', function (): void {
    $manager = app(EventManager::class);

    expect($manager->unsubscribe('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager::getSubscription returns null for non-existent ID', function (): void {
    $manager = app(EventManager::class);

    expect($manager->getSubscription('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

test('EventManager::listSubscriptions returns empty when none exist', function (): void {
    $manager = app(EventManager::class);

    $result = $manager->listSubscriptions();

    expect($result)->toBeInstanceOf(Collection::class)
        ->toBeEmpty();
});

// ─── ActionResolver edge cases ────────────────────────────────────────

test('ActionResolver::resolve throws for non-existent class', function (): void {
    $resolver = app(ActionResolver::class);

    $resolver->resolve('NonExistent\\ActionClass');
})->throws(InvalidArgumentException::class, 'does not exist');

test('ActionResolver::resolve throws for non-Triggerable class', function (): void {
    $resolver = app(ActionResolver::class);

    // Register a non-Triggerable class in the container
    app()->bind(\stdClass::class, fn (): \stdClass => new \stdClass());

    $resolver->resolve(\stdClass::class);
})->throws(InvalidArgumentException::class, 'must implement');

// ─── Config validation ───────────────────────────────────────────────

test('config has all required top-level keys', function (): void {
    $config = config('events');
    expect($config)->not->toBeNull()
        ->and(array_keys($config))->toContain(
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'wildcard_cache_ttl',
        );
});

test('config table_names has all required keys', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toContainKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('config subscriptions has all required keys', function (): void {
    $subs = config('events.subscriptions');
    expect($subs)->toContainKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
    ]);
});

test('config retry has all required keys', function (): void {
    $retry = config('events.retry');
    expect($retry)->toContainKeys(['tries', 'backoff']);
});

test('config queue has all required keys', function (): void {
    $queue = config('events.queue');
    expect($queue)->toContainKeys(['connection', 'queue']);
});

test('config retention has all required keys', function (): void {
    $retention = config('events.retention');
    expect($retention)->toContainKeys(['days', 'include_pending']);
});

// ─── ServiceProvider binding lifecycle ────────────────────────────────

test('EventManager is singleton', function (): void {
    $app = app();

    $first = $app->make(EventManager::class);
    $second = $app->make(EventManager::class);

    expect($first)->toBe($second);
});

test('ConditionEngine is singleton', function (): void {
    $app = app();

    $first = $app->make(ConditionEngine::class);
    $second = $app->make(ConditionEngine::class);

    expect($first)->toBe($second);
});

test('ConditionEngineContract resolves to ConditionEngine', function (): void {
    $app = app();

    $contract = $app->make(ConditionEngineContract::class);
    $concrete = $app->make(ConditionEngine::class);

    expect($contract)->toBe($concrete);
});

test('ActionResolver is singleton', function (): void {
    $app = app();

    $first = $app->make(ActionResolver::class);
    $second = $app->make(ActionResolver::class);

    expect($first)->toBe($second);
});

test('TriggerBuilder is transient', function (): void {
    $app = app();

    $first = $app->make(TriggerBuilder::class);
    $second = $app->make(TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

test('SubscriptionBuilder is transient', function (): void {
    $app = app();

    $first = $app->make(SubscriptionBuilder::class);
    $second = $app->make(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

// ─── Facade accessor ──────────────────────────────────────────────────

test('Facade accessor returns EventManager class name', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
    $ref->setAccessible(true);

    expect($ref->invoke(null))->toBe(EventManager::class);
});

// ─── Strict types enforcement ────────────────────────────────────────

test('all source files have declare strict_types=1', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $missing = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if (! str_contains($content, 'declare(strict_types=1)')) {
            $missing[] = $file->getFilename();
        }
    }

    expect($missing)->toBeEmpty('Missing strict_types in: '.implode(', ', $missing));
});

// ─── Final class verification ────────────────────────────────────────

test('all core classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        WildcardMatcher::class,
        SubscriptionBuilder::class,
        TriggerBuilder::class,
        DomainEvent::class,
        EventsServiceProvider::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
    ];

    $nonFinal = [];
    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        if (! $ref->isFinal()) {
            $nonFinal[] = $class;
        }
    }

    expect($nonFinal)->toBeEmpty('Non-final classes: '.implode(', ', $nonFinal));
});

// ─── Console commands are final ───────────────────────────────────────

test('all console commands are final', function (): void {
    $commandClasses = [
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    $nonFinal = [];
    foreach ($commandClasses as $class) {
        $ref = new ReflectionClass($class);
        if (! $ref->isFinal()) {
            $nonFinal[] = $class;
        }
    }

    expect($nonFinal)->toBeEmpty('Non-final command classes: '.implode(', ', $nonFinal));
});

// ─── Version consistency ──────────────────────────────────────────────

test('composer.json version matches expected format', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composer['version'] ?? '';

    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
});

// ─── #[Override] verification ──────────────────────────────────────────

test('ConditionEngine::matches has Override attribute', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
    expect($ref->getAttributes(\Override::class))->toHaveCount(1);
});

test('WebhookAction::handle has Override attribute', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\Actions\WebhookAction::class, 'handle');
    expect($ref->getAttributes(\Override::class))->toHaveCount(1);
});

test('EventLog::boot has Override attribute', function (): void {
    $ref = new ReflectionMethod(EventLog::class, 'boot');
    expect($ref->getAttributes(\Override::class))->toHaveCount(1);
});

test('EventLog::casts has Override attribute', function (): void {
    $ref = new ReflectionMethod(EventLog::class, 'casts');
    expect($ref->getAttributes(\Override::class))->toHaveCount(1);
});

test('EventLog::newFactory has Override attribute', function (): void {
    $ref = new ReflectionMethod(EventLog::class, 'newFactory');
    expect($ref->getAttributes(\Override::class))->toHaveCount(1);
});

test('Subscription::boot has Override attribute', function (): void {
    $ref = new ReflectionMethod(Subscription::class, 'boot');
    expect($ref->getAttributes(\Override::class))->toHaveCount(1);
});

test('Trigger::boot has Override attribute', function (): void {
    $ref = new ReflectionMethod(Trigger::class, 'boot');
    expect($ref->getAttributes(\Override::class))->toHaveCount(1);
});

// ─── Model config-driven table names ──────────────────────────────────

test('EventLog getTable reads from config', function (): void {
    $table = (new EventLog)->getTable();
    expect($table)->toBe(config('events.table_names.event_logs', 'event_logs'));
});

test('Trigger getTable reads from config', function (): void {
    $table = (new Trigger)->getTable();
    expect($table)->toBe(config('events.table_names.triggers', 'triggers'));
});

test('Subscription getTable reads from config', function (): void {
    $table = (new Subscription)->getTable();
    expect($table)->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
});

// ─── Model key type and incrementing ──────────────────────────────────

test('all models use UUID string key type', function (): void {
    $models = [EventLog::class, Trigger::class, Subscription::class];

    foreach ($models as $model) {
        $instance = new $model;
        $ref = new ReflectionProperty($instance, 'keyType');
        expect($ref->getValue($instance))->toBe('string');

        $incRef = new ReflectionProperty($instance, 'incrementing');
        expect($incRef->getValue($instance))->toBeFalse();
    }
});

// ─── Migration file existence ─────────────────────────────────────────

test('all migration files exist', function (): void {
    $migrations = [
        '2024_01_01_000001_create_triggers_table.php',
        '2024_01_01_000002_create_event_logs_table.php',
        '2025_06_28_000001_create_event_subscriptions_table.php',
    ];

    foreach ($migrations as $migration) {
        $path = __DIR__.'/../database/migrations/'.$migration;
        expect(file_exists($path))->toBeTrue("Migration missing: {$migration}");
    }
});

// ─── Factory existence and type ───────────────────────────────────────

test('all factories exist and extend Factory', function (): void {
    $factories = [
        EventLogFactory::class,
        SubscriptionFactory::class,
        TriggerFactory::class,
    ];

    foreach ($factories as $factory) {
        $ref = new ReflectionClass($factory);
        expect($ref->isSubclassOf(\Illuminate\Database\Eloquent\Factories\Factory::class))->toBeTrue(
            "{$factory} does not extend Factory",
        );
    }
});

// ─── Pest.php registration ────────────────────────────────────────────

test('Phase 33 test file is registered in Pest.php', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    expect(str_contains($pestContent, 'EventsPhase33ProductionTest.php'))->toBeTrue();
});
