<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// ─── EventManager::listTriggers ─────────────────────────────────────────────

it('can list all triggers without filters', function (): void {
    Trigger::factory()->count(3)->create();

    $manager = $this->app->make(EventManager::class);
    $triggers = $manager->listTriggers();

    expect($triggers)->toHaveCount(3);
});

it('can list triggers filtered by event name', function (): void {
    Trigger::factory()->create(['event' => 'order.placed']);
    Trigger::factory()->create(['event' => 'user.created']);
    Trigger::factory()->create(['event' => 'order.shipped']);

    $manager = $this->app->make(EventManager::class);
    $triggers = $manager->listTriggers('order.placed');

    expect($triggers)->toHaveCount(1);
    expect($triggers->first()->event)->toBe('order.placed');
});

it('can list triggers filtered by wildcard event', function (): void {
    Trigger::factory()->create(['event' => 'order.placed']);
    Trigger::factory()->create(['event' => 'order.shipped']);
    Trigger::factory()->create(['event' => 'user.created']);

    $manager = $this->app->make(EventManager::class);
    $triggers = $manager->listTriggers('order.*');

    expect($triggers)->toHaveCount(2);
});

it('can list triggers filtered by enabled status', function (): void {
    Trigger::factory()->enabled()->count(2)->create();
    Trigger::factory()->disabled()->create();

    $manager = $this->app->make(EventManager::class);
    $enabled = $manager->listTriggers(null, true);
    $disabled = $manager->listTriggers(null, false);

    expect($enabled)->toHaveCount(2);
    expect($disabled)->toHaveCount(1);
});

it('respects limit parameter in listTriggers', function (): void {
    Trigger::factory()->count(5)->create();

    $manager = $this->app->make(EventManager::class);
    $triggers = $manager->listTriggers(null, null, 2);

    expect($triggers)->toHaveCount(2);
});

it('returns empty collection when no triggers match filter', function (): void {
    Trigger::factory()->create(['event' => 'order.placed']);

    $manager = $this->app->make(EventManager::class);
    $triggers = $manager->listTriggers('nonexistent.event');

    expect($triggers)->toHaveCount(0);
});

// ─── EventManager::getTrigger ───────────────────────────────────────────────

it('returns a trigger by ID', function (): void {
    $trigger = Trigger::factory()->create();

    $manager = $this->app->make(EventManager::class);
    $found = $manager->getTrigger($trigger->id);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($trigger->id);
});

it('returns null for non-existent trigger ID', function (): void {
    $manager = $this->app->make(EventManager::class);
    $found = $manager->getTrigger('00000000-0000-0000-0000-000000000000');

    expect($found)->toBeNull();
});

// ─── EventManager::deleteTrigger ───────────────────────────────────────────

it('deletes a trigger by ID and invalidates cache', function (): void {
    $trigger = Trigger::factory()->create();

    $manager = $this->app->make(EventManager::class);
    $result = $manager->deleteTrigger($trigger->id);

    expect($result)->toBeTrue();
    expect(Trigger::find($trigger->id))->toBeNull();
});

it('returns false when deleting non-existent trigger', function (): void {
    $manager = $this->app->make(EventManager::class);
    $result = $manager->deleteTrigger('00000000-0000-0000-0000-000000000000');

    expect($result)->toBeFalse();
});

it('invalidates trigger cache after deleteTrigger', function (): void {
    $trigger = Trigger::factory()->create(['event' => 'order.*']);

    $manager = $this->app->make(EventManager::class);

    // Warm the cache by firing a matching event
    // (This loads wildcard triggers into cache)
    $manager->deleteTrigger($trigger->id);

    // Cache should be empty after delete
    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
});

// ─── EventManager fireModel with various model types ─────────────────────────

it('fireModel works with models that have attributesToArray', function (): void {
    $trigger = Trigger::factory()
        ->enabled()
        ->create([
            'event' => 'App\\Models\\Order.created',
            'action' => TestNullAction::class,
            'conditions' => [],
        ]);

    $model = new class {
        public function attributesToArray(): array
        {
            return ['id' => 1, 'status' => 'active', 'total' => 99.99];
        }
    };

    $manager = $this->app->make(EventManager::class);
    $manager->fireModel('App\\Models\\Order', 'created', $model);

    $log = EventLog::where('trigger_id', $trigger->id)->first();
    expect($log)->not->toBeNull();
    expect($log->event)->toBe('App\\Models\\Order.created');
    expect($log->payload)->toHaveKey('id');
    expect($log->payload)->toHaveKey('status');
    expect($log->payload)->toHaveKey('model_class');
    expect($log->payload)->toHaveKey('action');
});

it('fireModel works with models that only have toArray', function (): void {
    $trigger = Trigger::factory()
        ->enabled()
        ->create([
            'event' => 'App\\Models\\Product.updated',
            'action' => TestNullAction::class,
            'conditions' => [],
        ]);

    $model = new class {
        public function toArray(): array
        {
            return ['sku' => 'ABC-123', 'price' => 25.00];
        }
    };

    $manager = $this->app->make(EventManager::class);
    $manager->fireModel('App\\Models\\Product', 'updated', $model);

    $log = EventLog::where('trigger_id', $trigger->id)->first();
    expect($log)->not->toBeNull();
    expect($log->payload)->toHaveKey('sku');
});

// ─── TriggerBuilder save with multiple actions ────────────────────────────────

it('TriggerBuilder saves multiple actions as JSON array', function (): void {
    $manager = $this->app->make(EventManager::class);
    $trigger = $manager->on('multi.action.test')
        ->actions([TestNullAction::class, \ZeroBoiler\Events\Tests\Actions\AnotherAction'])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveCount(2);
    expect($decoded[0])->toBe(TestNullAction::class);
});

it('TriggerBuilder saves single action as plain class name string', function (): void {
    $manager = $this->app->make(EventManager::class);
    $trigger = $manager->on('single.action.test')
        ->action(TestNullAction::class)
        ->save();

    expect($trigger->action)->toBe(TestNullAction::class);
    // Verify it's a plain string, not JSON
    json_decode($trigger->action);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    $decoded = json_decode($trigger->action);
    expect($decoded)->not->toBeArray(); // Single class is stored as string
});

// ─── Trigger model getTable uses config ──────────────────────────────────────

it('Trigger model uses config table name', function (): void {
    config(['events.table_names.triggers' => 'custom_triggers']);

    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('custom_triggers');
});

it('Trigger model falls back to default when config is invalid', function (): void {
    config(['events.table_names.triggers' => 123]); // non-string

    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('triggers');
});

// ─── EventLog status lifecycle ──────────────────────────────────────────────

it('EventLog transitions from pending to completed', function (): void {
    $log = EventLog::factory()->pending()->create();
    expect($log->status)->toBe(EventLog::STATUS_PENDING);

    $log->markAsCompleted(150);
    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBe(150);
});

it('EventLog transitions from pending to failed', function (): void {
    $log = EventLog::factory()->pending()->create();
    expect($log->status)->toBe(EventLog::STATUS_PENDING);

    $log->markAsFailed('Connection timeout');
    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBe('Connection timeout');
});

// ─── DomainEvent edge cases ─────────────────────────────────────────────────

it('DomainEvent fromArray handles missing eventType gracefully', function (): void {
    $event = DomainEvent::fromArray(['payload' => ['key' => 'value']]);

    expect($event->eventType)->toBe('');
    expect($event->payload)->toBe(['key' => 'value']);
});

it('DomainEvent fromArray handles missing payload gracefully', function (): void {
    $event = DomainEvent::fromArray(['eventType' => 'test.event']);

    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe([]);
});

it('DomainEvent fromArray handles completely empty array', function (): void {
    $event = DomainEvent::fromArray([]);

    expect($event->eventType)->toBe('');
    expect($event->payload)->toBe([]);
    expect($event->eventId)->not->toBeNull();
    expect($event->occurredAt)->not->toBeNull();
});

it('DomainEvent toArray roundtrip preserves all fields', function (): void {
    $original = DomainEvent::occur('user.registered', [
        'user_id' => 42,
        'email' => 'test@example.com',
    ]);

    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
});

// ─── ConditionEngine comprehensive operator tests ───────────────────────────

it('ConditionEngine: starts_with operator', function (): void {
    $engine = new ConditionEngine;
    expect($engine->matches(['name' => ['starts_with', 'He']], ['name' => 'Hello']))->toBeTrue();
    expect($engine->matches(['name' => ['starts_with', 'He']], ['name' => 'World']))->toBeFalse();
    expect($engine->matches(['name' => ['starts_with', 'He']], ['name' => null]))->toBeFalse();
});

it('ConditionEngine: ends_with operator', function (): void {
    $engine = new ConditionEngine;
    expect($engine->matches(['name' => ['ends_with', 'lo']], ['name' => 'Hello']))->toBeTrue();
    expect($engine->matches(['name' => ['ends_with', 'lo']], ['name' => 'World']))->toBeFalse();
});

it('ConditionEngine: not_empty operator', function (): void {
    $engine = new ConditionEngine;
    expect($engine->matches(['name' => ['not_empty']], ['name' => 'Hello']))->toBeTrue();
    expect($engine->matches(['name' => ['not_empty']], ['name' => '']))->toBeFalse();
    expect($engine->matches(['name' => ['not_empty']], ['name' => 0]))->toBeFalse();
    expect($engine->matches(['name' => ['not_empty']], ['name' => null]))->toBeFalse();
});

it('ConditionEngine: between operator auto-normalizes inverted range', function (): void {
    $engine = new ConditionEngine;
    expect($engine->matches(['age' => ['between', [100, 50]]], ['age' => 75]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [100, 50]]], ['age' => 25]))->toBeFalse();
});

it('ConditionEngine: dot notation field access', function (): void {
    $engine = new ConditionEngine;
    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => ['role' => 'admin', 'name' => 'John']],
    ))->toBeTrue();

    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => ['role' => 'user', 'name' => 'Jane']],
    ))->toBeFalse();
});

it('ConditionEngine: multiple conditions must all pass', function (): void {
    $engine = new ConditionEngine;
    expect($engine->matches(
        ['age' => ['>=', 18], 'status' => 'active'],
        ['age' => 25, 'status' => 'active'],
    ))->toBeTrue();

    expect($engine->matches(
        ['age' => ['>=', 18], 'status' => 'active'],
        ['age' => 15, 'status' => 'active'],
    ))->toBeFalse();

    expect($engine->matches(
        ['age' => ['>=', 18], 'status' => 'active'],
        ['age' => 25, 'status' => 'inactive'],
    ))->toBeFalse();
});

// ─── WildcardMatcher comprehensive tests ─────────────────────────────────────

it('WildcardMatcher: single segment wildcard matches one segment', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.shipped'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
});

it('WildcardMatcher: double segment wildcard matches across boundaries', function (): void {
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra.detail'))->toBeTrue();
});

it('WildcardMatcher: catch-all patterns match everything', function (): void {
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', 'deeply.nested.event.name'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();

    expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
});

it('WildcardMatcher: multiple single wildcards', function (): void {
    expect(WildcardMatcher::matches('*.created', 'order.created'))->toBeTrue();
    expect(WildcardMatcher::matches('*.created', 'user.created'))->toBeTrue();
    expect(WildcardMatcher::matches('*.created', 'order.updated'))->toBeFalse();
});

it('WildcardMatcher: extractWildcards returns correct segments', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
    expect($result)->toBe(['profile']);

    $result = WildcardMatcher::extractWildcards('*.order.*', 'new.order.placed');
    expect($result)->toBe(['new', 'placed']);
});

it('WildcardMatcher: extractWildcards returns empty for cross-segment', function (): void {
    $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');
    expect($result)->toBe([]);
});

it('WildcardMatcher: extractWildcards returns empty when segments dont match', function (): void {
    $result = WildcardMatcher::extractWildcards('a.*.c', 'x.y.z');
    expect($result)->toBe([]);
});

// ─── Config consistency ─────────────────────────────────────────────────────

it('all config sections are present and properly typed', function (): void {
    $config = config('events');
    expect($config)->toBeArray();

    expect($config)->toHaveKey('table_names');
    expect($config)->toHaveKey('queue');
    expect($config)->toHaveKey('retry');
    expect($config)->toHaveKey('retention');
    expect($config)->toHaveKey('subscriptions');
    expect($config)->toHaveKey('wildcard_cache_ttl');

    // Verify nested structure
    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');

    expect($config['queue'])->toHaveKey('connection');
    expect($config['queue'])->toHaveKey('queue');

    expect($config['retry'])->toHaveKey('tries');
    expect($config['retry'])->toHaveKey('backoff');

    expect($config['subscriptions'])->toHaveKey('auto_generate_secret');
    expect($config['subscriptions'])->toHaveKey('max_failures');
    expect($config['subscriptions'])->toHaveKey('timeout');
    expect($config['subscriptions'])->toHaveKey('signature_algorithm');
});

// ─── ServiceProvider binding verification ─────────────────────────────────────

it('EventManagers wildcardToLike is accessible via trait', function (): void {
    $manager = $this->app->make(EventManager::class);
    $reflection = new ReflectionMethod($manager, 'wildcardToLike');
    expect($reflection->isProtected())->toBeTrue();
});

// ─── Subscription model signPayload edge cases ──────────────────────────────

it('Subscription signPayload returns empty string when secret is empty', function (): void {
    $sub = Subscription::factory()->withoutSecret()->create();
    expect($sub->signPayload('test payload'))->toBe('');
});

it('Subscription signPayload returns empty string when secret is null', function (): void {
    $sub = Subscription::factory()->make(['secret' => null]);
    expect($sub->signPayload('test payload'))->toBe('');
});

it('Subscription signPayload uses sha256 by default', function (): void {
    $sub = Subscription::factory()->create(['secret' => 'test_secret']);
    $expected = hash_hmac('sha256', 'test payload', 'test_secret');
    expect($sub->signPayload('test payload'))->toBe($expected);
});

// ─── Trigger condition guard in shouldDispatch ──────────────────────────────

it('trigger with empty conditions always dispatches', function (): void {
    $trigger = Trigger::factory()->enabled()->create([
        'conditions' => [],
        'action' => TestNullAction::class,
        'event' => 'test.dispatch',
        'async' => false,
    ]);

    $manager = $this->app->make(EventManager::class);
    $manager->fire('test.dispatch', ['key' => 'value']);

    $log = EventLog::where('trigger_id', $trigger->id)->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
});

// ─── fire() validation ───────────────────────────────────────────────────────

it('fire throws exception for empty event name', function (): void {
    $manager = $this->app->make(EventManager::class);
    $manager->fire('');
})->throws(InvalidArgumentException::class, 'Event name cannot be empty');

it('fire throws exception for zero-string event name', function (): void {
    $manager = $this->app->make(EventManager::class);
    $manager->fire('0');
})->throws(InvalidArgumentException::class, 'Event name cannot be empty');

it('fireModel throws exception for empty model class', function (): void {
    $manager = $this->app->make(EventManager::class);
    $manager->fireModel('', 'created', new stdClass);
})->throws(InvalidArgumentException::class, 'Model class name cannot be empty');

it('fireModel throws exception for empty action', function (): void {
    $manager = $this->app->make(EventManager::class);
    $manager->fireModel('App\\Models\\Order', '', new stdClass);
})->throws(InvalidArgumentException::class, 'Model action cannot be empty');

// ─── Cache invalidation ─────────────────────────────────────────────────────

it('invalidateTriggerCache clears the cache key', function (): void {
    Cache::put('zeroboiler:events:enabled_wildcard_triggers', collect(), 300);
    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

    $manager = $this->app->make(EventManager::class);
    $manager->invalidateTriggerCache();

    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
});

it('enable invalidates trigger cache', function (): void {
    Trigger::factory()->disabled()->create();

    $manager = $this->app->make(EventManager::class);
    // Warm cache
    Cache::put('zeroboiler:events:enabled_wildcard_triggers', collect(), 300);

    // Enable any trigger (cache should clear)
    $trigger = Trigger::first();
    $manager->enable($trigger->id);

    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
});

it('disable invalidates trigger cache', function (): void {
    Trigger::factory()->enabled()->create();

    $manager = $this->app->make(EventManager::class);
    Cache::put('zeroboiler:events:enabled_wildcard_triggers', collect(), 300);

    $trigger = Trigger::first();
    $manager->disable($trigger->id);

    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
});

// ─── Test helper classes ───────────────────────────────────────────────────

if (! class_exists('TestNullAction')) {
    /**
     * No-op triggerable action for testing fire dispatch without side effects.
     */
    final class TestNullAction implements Triggerable
    {
        public function handle(array $payload): void
        {
            // No-op
        }
    }
}
