<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

// ─────────────────────────────────────────────────
// SubscriptionBuilder Validation Tests
// ─────────────────────────────────────────────────

describe('SubscriptionBuilder validation', function (): void {
    test('save throws when event name is empty', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->to('https://example.com/webhook');

        expect(fn () => $builder->save())->toThrow(InvalidArgumentException::class);
    });

    test('save throws when webhook URL is empty', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->on('order.placed');

        expect(fn () => $builder->save())->toThrow(InvalidArgumentException::class);
    });

    test('save throws when webhook URL is invalid', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->on('order.placed')->to('not-a-valid-url');

        expect(fn () => $builder->save())->toThrow(InvalidArgumentException::class);
    });

    test('save auto-generates HMAC secret when none provided', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $subscription = $builder
            ->on('order.placed')
            ->to('https://example.com/webhook')
            ->save();

        expect($subscription->secret)->not->toBeNull()
            ->and($subscription->secret)->toBeString()
            ->and($subscription->secret)->toMatch('/^whsec_/');
    });

    test('save uses provided secret', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $subscription = $builder
            ->on('order.placed')
            ->to('https://example.com/webhook')
            ->withSecret('custom_secret_123')
            ->save();

        expect($subscription->secret)->toBe('custom_secret_123');
    });

    test('save stores conditions when provided', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $subscription = $builder
            ->on('order.placed')
            ->to('https://example.com/webhook')
            ->withFilter(['amount' => ['>', 100]])
            ->save();

        expect($subscription->conditions)->toBe(['amount' => ['>', 100]]);
    });

    test('save stores null conditions when no filter provided', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $subscription = $builder
            ->on('order.placed')
            ->to('https://example.com/webhook')
            ->save();

        expect($subscription->conditions)->toBeNull();
    });
});

// ─────────────────────────────────────────────────
// TriggerBuilder action() + actions() Merge (BUG-2)
// ─────────────────────────────────────────────────

describe('TriggerBuilder action merging', function (): void {
    test('save merges single action() and actions() correctly', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('merge.test')
            ->action(SendOrderNotification::class)
            ->actions([LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveCount(2)
            ->and($decoded[0])->toBe(SendOrderNotification::class)
            ->and($decoded[1])->toBe(LogOrderEvent::class);
    });

    test('save deduplicates action present in both action() and actions()', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('dedup.test')
            ->action(SendOrderNotification::class)
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveCount(2);
    });

    test('save with only action() stores plain class name', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('single.action')
            ->action(SendOrderNotification::class)
            ->save();

        expect($trigger->action)->toBe(SendOrderNotification::class);
    });

    test('save with only actions() stores JSON array', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('multi.actions')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveCount(2);
    });

    test('save with actionParams and single action stores class+params JSON', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('params.single')
            ->action(SendOrderNotification::class)
            ->actionParams(['url' => 'https://example.com/hooks'])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveKey('class')
            ->and($decoded['class'])->toBe(SendOrderNotification::class)
            ->and($decoded)->toHaveKey('params')
            ->and($decoded['params']['url'])->toBe('https://example.com/hooks');
    });

    test('save with actionParams and multiple actions uses classes key', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('params.multi')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->actionParams(['topic' => 'orders'])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveKey('classes')
            ->and($decoded['classes'])->toBe([SendOrderNotification::class, LogOrderEvent::class])
            ->and($decoded)->toHaveKey('params')
            ->and($decoded['params']['topic'])->toBe('orders');
    });
});

// ─────────────────────────────────────────────────
// ManagesSubscriptions::subscribeWebhook Tests
// ─────────────────────────────────────────────────

describe('EventManager subscribeWebhook', function (): void {
    test('subscribeWebhook creates a trigger with WebhookAction', function (): void {
        $triggerId = EventManagerFacade::subscribeWebhook(
            'order.placed',
            'https://partner.com/hooks',
            ['status' => 'paid'],
            50,
        );

        expect($triggerId)->toBeString()
            ->and(Trigger::count())->toBe(1);

        $trigger = Trigger::first();
        expect($trigger->event)->toBe('order.placed')
            ->and($trigger->enabled)->toBeTrue()
            ->and($trigger->priority)->toBe(50);

        $decoded = json_decode($trigger->action, true);
        expect($decoded['class'])->toBe(WebhookAction::class)
            ->and($decoded['params']['url'])->toBe('https://partner.com/hooks');
    });

    test('subscribeWebhook stores conditions on the trigger', function (): void {
        EventManagerFacade::subscribeWebhook(
            'order.placed',
            'https://partner.com/hooks',
            ['amount' => ['>', 50]],
        );

        $trigger = Trigger::first();
        expect($trigger->conditions)->toBe(['amount' => ['>', 50]]);
    });
});

// ─────────────────────────────────────────────────
// ManagesHistory::purgeLogs Tests
// ─────────────────────────────────────────────────

describe('EventManager purgeLogs', function (): void {
    test('purgeLogs deletes completed logs older than threshold', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(60),
        ]);
        EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(10),
        ]);

        $deleted = EventManagerFacade::purgeLogs(Carbon::now()->subDays(30));

        expect($deleted)->toBe(1)
            ->and(EventLog::count())->toBe(1);
    });

    test('purgeLogs with includePending also purges pending logs', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(60),
        ]);
        EventLog::factory()->pending()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $deletedWithoutPending = EventManagerFacade::purgeLogs(Carbon::now()->subDays(30));
        expect($deletedWithoutPending)->toBe(1);

        $deletedWithPending = EventManagerFacade::purgeLogs(Carbon::now()->subDays(30), includePending: true);
        expect($deletedWithPending)->toBe(1)
            ->and(EventLog::count())->toBe(0);
    });

    test('purgeLogs does not delete recent logs', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $deleted = EventManagerFacade::purgeLogs(Carbon::now()->subDays(30));

        expect($deleted)->toBe(0)
            ->and(EventLog::count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────
// WildcardMatcher::findMatchingPatterns
// ─────────────────────────────────────────────────

describe('WildcardMatcher findMatchingPatterns', function (): void {
    test('finds exact matches', function (): void {
        $patterns = ['order.placed', 'user.created', 'payment.received'];

        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matches)->toBe(['order.placed']);
    });

    test('finds wildcard matches', function (): void {
        $patterns = ['order.*', 'user.created', 'payment.*'];

        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matches)->toBe(['order.*']);
    });

    test('finds multiple matching patterns', function (): void {
        $patterns = ['*', 'order.*', 'order.placed'];

        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matches)->toBe(['*', 'order.*', 'order.placed']);
    });

    test('returns empty array when no patterns match', function (): void {
        $patterns = ['user.*', 'payment.*'];

        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matches)->toBe([]);
    });

    test('catch-all pattern matches multi-segment events', function (): void {
        $patterns = ['*'];

        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'a.b.c.d');

        expect($matches)->toBe(['*']);
    });
});

// ─────────────────────────────────────────────────
// DomainEvent Enhanced Tests
// ─────────────────────────────────────────────────

describe('DomainEvent', function (): void {
    test('occur preserves eventType and payload', function (): void {
        $event = DomainEvent::occur('user.registered', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        expect($event->eventType)->toBe('user.registered')
            ->and($event->payload)->toBe([
                'email' => 'test@example.com',
                'name' => 'Test User',
            ]);
    });

    test('fromArray preserves original eventId and occurredAt', function (): void {
        $original = DomainEvent::occur('order.placed', ['order_id' => 123]);
        $data = $original->toArray();

        // Sleep a tiny bit to ensure time has passed
        usleep(1000);

        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString())
            ->and($restored->occurredAt)->toEqual($original->occurredAt)
            ->and($restored->eventType)->toBe('order.placed')
            ->and($restored->payload)->toBe(['order_id' => 123]);
    });

    test('fromArray handles missing eventId gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => [],
        ]);

        expect($event->eventId)->not->toBeNull()
            ->and($event->eventType)->toBe('test.event');
    });

    test('fromArray handles invalid eventId gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-valid-uuid',
            'payload' => [],
        ]);

        // Should generate a fresh UUID instead of throwing
        expect($event->eventId)->not->toBeNull();
    });

    test('fromArray handles missing occurredAt gracefully', function (): void {
        $before = new \DateTimeImmutable();
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => [],
        ]);
        $after = new \DateTimeImmutable();

        expect($event->occurredAt)->not->toBeNull()
            ->and($event->occurredAt->getTimestamp())
                ->toBeGreaterThanOrEqual($before->getTimestamp())
            ->and($event->occurredAt->getTimestamp())
                ->toBeLessThanOrEqual($after->getTimestamp());
    });

    test('fromArray handles invalid occurredAt gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-date',
            'payload' => [],
        ]);

        expect($event->occurredAt)->not->toBeNull();
    });

    test('toArray contains all required keys', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        $data = $event->toArray();

        expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt'])
            ->and($data['eventType'])->toBe('test.event')
            ->and($data['payload'])->toBe(['key' => 'value'])
            ->and($data['eventId'])->toBe($event->eventId->toString())
            ->and($data['occurredAt'])->toBe($event->occurredAt->format(\DateTimeImmutable::ATOM));
    });

    test('fromArray with empty array uses defaults', function (): void {
        $event = DomainEvent::fromArray([]);

        expect($event->eventType)->toBe('')
            ->and($event->payload)->toBe([])
            ->and($event->eventId)->not->toBeNull()
            ->and($event->occurredAt)->not->toBeNull();
    });
});

// ─────────────────────────────────────────────────
// Subscription Model Tests
// ─────────────────────────────────────────────────

describe('Subscription model', function (): void {
    test('signPayload returns empty string for null secret', function (): void {
        $subscription = Subscription::factory()->create(['secret' => null]);

        expect($subscription->signPayload('test-payload'))->toBe('');
    });

    test('signPayload returns empty string for empty secret', function (): void {
        $subscription = Subscription::factory()->create(['secret' => '']);

        expect($subscription->signPayload('test-payload'))->toBe('');
    });

    test('hasExceededFailures returns true when at threshold', function (): void {
        $subscription = Subscription::factory()->create(['failure_count' => 10]);

        expect($subscription->hasExceededFailures(10))->toBeTrue()
            ->and($subscription->hasExceededFailures(11))->toBeFalse();
    });

    test('recordFailure increments failure count', function (): void {
        $subscription = Subscription::factory()->create(['failure_count' => 0]);

        $subscription->recordFailure();
        $subscription->refresh();

        expect($subscription->failure_count)->toBe(1);
    });

    test('resetFailures sets failure count to zero', function (): void {
        $subscription = Subscription::factory()->create(['failure_count' => 5]);

        $subscription->resetFailures();
        $subscription->refresh();

        expect($subscription->failure_count)->toBe(0);
    });

    test('matchesEvent uses exact match for non-wildcard', function (): void {
        $subscription = Subscription::factory()->create(['event' => 'order.placed']);

        expect($subscription->matchesEvent('order.placed'))->toBeTrue()
            ->and($subscription->matchesEvent('order.shipped'))->toBeFalse();
    });

    test('matchesEvent delegates to WildcardMatcher for patterns', function (): void {
        $subscription = Subscription::factory()->create(['event' => 'order.*']);

        expect($subscription->matchesEvent('order.placed'))->toBeTrue()
            ->and($subscription->matchesEvent('order.placed.extra'))->toBeFalse()
            ->and($subscription->matchesEvent('user.placed'))->toBeFalse();
    });

    test('matchesEvent handles cross-segment wildcards', function (): void {
        $subscription = Subscription::factory()->create(['event' => 'order.**']);

        expect($subscription->matchesEvent('order.placed'))->toBeTrue()
            ->and($subscription->matchesEvent('order.placed.extra'))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────
// EventLog Model Tests
// ─────────────────────────────────────────────────

describe('EventLog model', function (): void {
    test('markAsCompleted updates status and duration', function (): void {
        $trigger = Trigger::factory()->create();
        $log = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_DISPATCHED,
            'duration_ms' => null,
        ]);

        $log->markAsCompleted(250);
        $log->refresh();

        expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->duration_ms)->toBe(250);
    });

    test('markAsFailed updates status and error', function (): void {
        $trigger = Trigger::factory()->create();
        $log = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_DISPATCHED,
            'error' => null,
        ]);

        $log->markAsFailed('Connection timeout');
        $log->refresh();

        expect($log->status)->toBe(EventLog::STATUS_FAILED)
            ->and($log->error)->toBe('Connection timeout');
    });
});

// ─────────────────────────────────────────────────
// Trigger Model Tests
// ─────────────────────────────────────────────────

describe('Trigger model scopes', function (): void {
    test('enabled scope returns only enabled triggers', function (): void {
        Trigger::factory()->create(['enabled' => true]);
        Trigger::factory()->create(['enabled' => false]);

        expect(Trigger::enabled()->count())->toBe(1);
    });

    test('async scope returns only async triggers', function (): void {
        Trigger::factory()->create(['async' => true]);
        Trigger::factory()->create(['async' => false]);

        expect(Trigger::async()->count())->toBe(1);
    });

    test('orderByPriority scope orders by priority descending', function (): void {
        $low = Trigger::factory()->create(['priority' => 10]);
        $high = Trigger::factory()->create(['priority' => 100]);

        $ordered = Trigger::orderByPriority()->get();

        expect($ordered->first()->id)->toBe($high->id)
            ->and($ordered->last()->id)->toBe($low->id);
    });

    test('trigger has many event logs', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->create(['trigger_id' => $trigger->id]);
        EventLog::factory()->create(['trigger_id' => $trigger->id]);

        expect($trigger->eventLogs()->count())->toBe(2);
    });
});

// ─────────────────────────────────────────────────
// ConditionEngine Extended Tests
// ─────────────────────────────────────────────────

describe('ConditionEngine additional operators', function (): void {
    test('not_contains operator works', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['tags' => ['not_contains', 'urgent']], ['tags' => 'normal']))
            ->toBeTrue()
            ->and($engine->matches(['tags' => ['not_contains', 'urgent']], ['tags' => 'urgent']))
            ->toBeFalse();
    });

    test('not_empty operator works', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['name' => ['not_empty']], ['name' => 'Test']))
            ->toBeTrue()
            ->and($engine->matches(['name' => ['not_empty']], ['name' => '']))
            ->toBeFalse()
            ->and($engine->matches(['name' => ['not_empty']], []))
            ->toBeFalse();
    });

    test('between operator normalizes inverted ranges', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 75]))
            ->toBeTrue();
    });

    test('nested array access works with dot notation', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(
            ['user.profile.role' => 'admin'],
            ['user' => ['profile' => ['role' => 'admin']]]
        ))->toBeTrue()
            ->and($engine->matches(
                ['user.profile.role' => 'admin'],
                ['user' => ['profile' => ['role' => 'user']]]
            ))->toBeFalse();
    });

    test('missing nested key returns null and fails null check', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['user.name' => ['null']], ['user' => ['age' => 25]]))
            ->toBeTrue()
            ->and($engine->matches(['user.name' => ['not_null']], ['user' => ['age' => 25]]))
            ->toBeFalse();
    });

    test('safeRegexMatch rejects long patterns', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        $longPattern = str_repeat('a', 501);

        expect($engine->matches(['code' => ['matches', $longPattern]], ['code' => str_repeat('a', 10)]))
            ->toBeFalse();
    });
});
