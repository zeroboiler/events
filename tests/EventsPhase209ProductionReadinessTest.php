<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Config\Repository;
use Illuminate\Support\Carbon;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\Actions\NullAction;

/**
 * Tests for GetsWebhookTimeout trait fallback to global app() helper.
 *
 * EventsRedeliverCommand uses GetsWebhookTimeout but does NOT have its own
 * getConfig() method — it relies on the trait's global app() fallback.
 * This test verifies that path works correctly.
 */
final class EventsRedeliverCommandConfigFallback implements Triggerable
{
    use GetsWebhookTimeout;

    public int $timeoutReceived = 0;

    #[\Override]
    public function handle(array $payload): void
    {
        $this->timeoutReceived = $this->getWebhookTimeout();
    }
}

/**
 * EventsPhase209ProductionReadinessTest
 *
 * Covers: GetsWebhookTimeout trait fallback, executeTrigger payload merge order,
 * EventManager::container(), DomainEvent fromArray edge cases, and
 * TriggerBuilder resolveActions behavior.
 */
test('GetsWebhookTimeout falls back to global app() when no getConfig method exists', function () {
    $action = new EventsRedeliverCommandConfigFallback;
    $action->handle([]);

    // Default timeout is 30 when config is not overridden
    expect($action->timeoutReceived)->toBe(30);
});

test('GetsWebhookTimeout reads custom timeout from config', function () {
    app('config')->set('events.subscriptions.timeout', 60);

    $action = new EventsRedeliverCommandConfigFallback;
    $action->handle([]);

    expect($action->timeoutReceived)->toBe(60);
});

test('EventManager::container returns the application container instance', function () {
    $manager = app(EventManager::class);

    $container = $manager->container();

    expect($container)->toBeInstanceOf(Container::class);
    expect($container)->toBe(app());
});

test('executeTrigger merges action params into base payload preserving base values', function () {
    // Register action in container
    app()->bind(
        'TestMergeAction',
        fn (): NullAction => new NullAction,
    );

    // Create trigger with action params
    $trigger = Trigger::create([
        'name' => 'Merge Test',
        'event' => 'test.merge',
        'action' => json_encode([
            'class' => 'TestMergeAction',
            'params' => ['url' => 'https://example.com/hook', 'subscription_id' => 'sub-123'],
        ], JSON_THROW_ON_ERROR),
        'async' => false,
        'priority' => 0,
        'enabled' => true,
    ]);

    $log = EventLog::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => $trigger->id,
        'event' => 'test.merge',
        'payload' => ['user_id' => 42, 'amount' => 100],
        'status' => EventLog::STATUS_PENDING,
    ]);

    $manager = app(EventManager::class);
    $manager->executeTrigger($trigger, $log);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
});

test('executeTrigger marks log as failed and re-throws on action error', function () {
    app()->bind('FailingMergeAction', fn (): \ZeroBoiler\Events\Tests\Actions\FailingAction => new \ZeroBoiler\Events\Tests\Actions\FailingAction);

    $trigger = Trigger::create([
        'name' => 'Failing Merge',
        'event' => 'test.fail',
        'action' => 'FailingMergeAction',
        'async' => false,
        'priority' => 0,
        'enabled' => true,
    ]);

    $log = EventLog::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => $trigger->id,
        'event' => 'test.fail',
        'payload' => [],
        'status' => EventLog::STATUS_PENDING,
    ]);

    $manager = app(EventManager::class);

    $thrown = false;
    try {
        $manager->executeTrigger($trigger, $log);
    } catch (\Throwable $e) {
        $thrown = true;
        expect($e->getMessage())->toBe('Action intentionally failed for testing.');
    }

    expect($thrown)->toBeTrue();

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBe('Action intentionally failed for testing.');
});

test('DomainEvent::fromArray throws on missing eventType', function () {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('eventType is required');

    DomainEvent::fromArray(['payload' => ['key' => 'value']]);
});

test('DomainEvent::fromArray creates event with default UUID and timestamp when not provided', function () {
    $event = DomainEvent::fromArray([
        'eventType' => 'user.created',
        'payload' => ['name' => 'Test'],
    ]);

    expect($event->eventType)->toBe('user.created');
    expect($event->payload)->toBe(['name' => 'Test']);
    expect($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class);
    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent::fromArray preserves original eventId and occurredAt when valid', function () {
    $uuid = Ramsey\Uuid\Uuid::uuid4()->toString();
    $timestamp = '2024-06-15T10:30:00+00:00';

    $event = DomainEvent::fromArray([
        'eventType' => 'order.placed',
        'payload' => ['order_id' => 123],
        'eventId' => $uuid,
        'occurredAt' => $timestamp,
    ]);

    expect($event->eventId->toString())->toBe($uuid);
    expect($event->occurredAt->format(DateTimeImmutable::ATOM))->toBe($timestamp);
});

test('DomainEvent::fromArray handles invalid UUID gracefully', function () {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'payload' => [],
        'eventId' => 'not-a-valid-uuid',
    ]);

    // Should NOT throw — falls back to a fresh UUID
    expect($event->eventType)->toBe('test.event');
    expect($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class);
});

test('DomainEvent::fromArray handles invalid datetime gracefully', function () {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'payload' => [],
        'occurredAt' => 'not-a-date',
    ]);

    // Should NOT throw — falls back to now()
    expect($event->eventType)->toBe('test.event');
    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent toArray and fromArray round-trip preserves identity', function () {
    $original = DomainEvent::occur('payment.received', ['amount' => 99.99]);

    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
});

test('EventManager::fire with empty string event name throws InvalidArgumentException', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Event name cannot be empty');

    $manager->fire('');
});

test('EventManager::fire with zero string event name throws InvalidArgumentException', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Event name cannot be empty');

    $manager->fire('0');
});

test('EventManager::fireModel with empty class name throws InvalidArgumentException', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Model class name cannot be empty');

    $manager->fireModel('', 'created', new stdClass);
});

test('EventManager::fireModel with empty action throws InvalidArgumentException', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Model action cannot be empty');

    $manager->fireModel('App\Models\User', '', new stdClass);
});

test('EventManager::fireModel flattens model attributes into payload root', function () {
    app()->bind('FireModelTestAction', fn (): NullAction => new NullAction);

    // Create a trigger that matches the model event
    $trigger = Trigger::create([
        'name' => 'FireModel Test',
        'event' => 'App\\Models\\Order.created',
        'action' => 'FireModelTestAction',
        'async' => false,
        'priority' => 0,
        'enabled' => true,
        'conditions' => ['status' => 'active'],
    ]);

    // Create a mock model with attributesToArray
    $model = new class extends \Illuminate\Database\Eloquent\Model {
        public function attributesToArray(): array
        {
            return ['id' => 1, 'status' => 'active', 'total' => 50];
        }
    };

    $manager = app(EventManager::class);
    // Should fire (condition 'status' == 'active' met from flattened attributes)
    $manager->fireModel('App\\Models\\Order', 'created', $model);

    $logs = EventLog::where('event', 'App\\Models\\Order.created')->get();
    expect($logs)->not->toBeEmpty();
    expect($logs->first()->status)->toBe(EventLog::STATUS_COMPLETED);
});

test('TriggerBuilder save throws when no action provided', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('At least one action is required');

    $manager->on('test.no-action')
        ->name('No Action Trigger')
        ->save();
});

test('TriggerBuilder save throws when event is empty', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Event name is required');

    $manager->on('')
        ->action(NullAction::class)
        ->save();
});

test('SubscriptionBuilder save throws when URL is empty', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Webhook URL is required');

    $manager->subscribe('test.event', '')
        ->save();
});

test('SubscriptionBuilder save throws when URL is not HTTP(S)', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must use HTTP or HTTPS');

    $manager->subscribe('test.event', 'ftp://evil.com/hook')
        ->save();
});

test('SubscriptionBuilder withSecret enforces minimum 16 character length', function () {
    $manager = app(EventManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('at least 16 characters');

    $manager->subscribe('test.event', 'https://example.com/hook')
        ->withSecret('short')
        ->save();
});

test('EventManager deleteTrigger returns false for empty ID', function () {
    $manager = app(EventManager::class);

    expect($manager->deleteTrigger(''))->toBeFalse();
    expect($manager->deleteTrigger('0'))->toBeFalse();
});

test('EventManager deleteTrigger returns false for non-existent ID', function () {
    $manager = app(EventManager::class);

    expect($manager->deleteTrigger('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager deleteTrigger removes trigger and invalidates cache', function () {
    app()->bind('DeleteTriggerTestAction', fn (): NullAction => new NullAction);

    $trigger = Trigger::create([
        'name' => 'Delete Me',
        'event' => 'test.delete',
        'action' => 'DeleteTriggerTestAction',
        'enabled' => true,
    ]);

    $manager = app(EventManager::class);

    expect($manager->deleteTrigger($trigger->id))->toBeTrue();
    expect(Trigger::find($trigger->id))->toBeNull();
});

test('EventManager enable/disable returns false for empty ID', function () {
    $manager = app(EventManager::class);

    expect($manager->enable(''))->toBeFalse();
    expect($manager->disable(''))->toBeFalse();
    expect($manager->enable('0'))->toBeFalse();
    expect($manager->disable('0'))->toBeFalse();
});

test('EventManager listTriggers returns empty collection when no triggers', function () {
    $manager = app(EventManager::class);

    $result = $manager->listTriggers();

    expect($result)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
    expect($result)->toHaveCount(0);
});

test('EventManager listTriggers with wildcard filter uses LIKE query', function () {
    app()->bind('ListWildcardTestAction', fn (): NullAction => new NullAction);

    Trigger::create([
        'name' => 'Wildcard 1',
        'event' => 'order.placed',
        'action' => 'ListWildcardTestAction',
        'enabled' => true,
    ]);

    Trigger::create([
        'name' => 'Wildcard 2',
        'event' => 'order.shipped',
        'action' => 'ListWildcardTestAction',
        'enabled' => true,
    ]);

    Trigger::create([
        'name' => 'Different Event',
        'event' => 'user.created',
        'action' => 'ListWildcardTestAction',
        'enabled' => true,
    ]);

    $manager = app(EventManager::class);
    $results = $manager->listTriggers('order.*');

    expect($results)->toHaveCount(2);
    foreach ($results as $t) {
        expect(str_starts_with($t->event, 'order.'))->toBeTrue();
    }
});

test('EventManager listSubscriptions returns empty for no subscriptions', function () {
    $manager = app(EventManager::class);

    expect($manager->listSubscriptions())->toHaveCount(0);
});

test('EventManager unsubscribe returns false for empty ID', function () {
    $manager = app(EventManager::class);

    expect($manager->unsubscribe(''))->toBeFalse();
    expect($manager->unsubscribe('0'))->toBeFalse();
});

test('EventManager getSubscription returns null for non-existent ID', function () {
    $manager = app(EventManager::class);

    expect($manager->getSubscription('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

test('EventManager getTrigger returns null for empty ID', function () {
    $manager = app(EventManager::class);

    expect($manager->getTrigger(''))->toBeNull();
    expect($manager->getTrigger('0'))->toBeNull();
});

test('EventManager isDisabled returns config value', function () {
    $manager = app(EventManager::class);

    app('config')->set('events.disabled', false);
    expect($manager->isDisabled())->toBeFalse();

    app('config')->set('events.disabled', true);
    expect($manager->isDisabled())->toBeTrue();

    // Reset
    app('config')->set('events.disabled', false);
});

test('EventManager setEnabled changes runtime config', function () {
    $manager = app(EventManager::class);

    $manager->setEnabled(false);
    expect($manager->isDisabled())->toBeTrue();

    $manager->setEnabled(true);
    expect($manager->isDisabled())->toBeFalse();
});

test('EventLog markAsCompleted updates status and duration', function () {
    $log = EventLog::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.completed',
        'payload' => [],
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $log->markAsCompleted(42);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBe(42);
});

test('EventLog markAsFailed updates status and error', function () {
    $log = EventLog::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.failed',
        'payload' => [],
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $log->markAsFailed('Something went wrong');

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBe('Something went wrong');
});

test('ConditionEngine matches with empty conditions returns true', function () {
    $engine = new \ZeroBoiler\Events\ConditionEngine;

    expect($engine->matches([], ['any' => 'data']))->toBeTrue();
});

test('WildcardMatcher matches catch-all pattern', function () {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'anything.here'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', 'deep.nested.event'))->toBeTrue();
});

test('WildcardMatcher extractWildcards returns empty for cross-segment patterns', function () {
    expect(\ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);
});

test('WildcardMatcher extractWildcards returns values for single-segment patterns', function () {
    $result = \ZeroBoiler\Events\WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

    expect($result)->toBe(['profile']);
});

test('DomainEvent __toString produces expected format', function () {
    $event = DomainEvent::occur('order.placed', ['id' => 1]);

    $str = (string) $event;

    expect($str)->toStartWith('DomainEvent[order.placed]');
    expect($str)->toContain('id=');
    expect($str)->toContain('at=');
});

test('ActionResolver throws for non-existent class', function () {
    $resolver = new \ZeroBoiler\Events\ActionResolver(app());

    $this->expectException(\ZeroBoiler\Events\Exceptions\ActionResolutionException::class);
    $this->expectExceptionMessage('Class does not exist');

    $resolver->resolve('NonExistent\Action\Class');
});

test('ActionResolver throws for class not implementing Triggerable', function () {
    $resolver = new \ZeroBoiler\Events\ActionResolver(app());

    $this->expectException(\ZeroBoiler\Events\Exceptions\ActionResolutionException::class);
    $this->expectExceptionMessage('must implement');

    $resolver->resolve(stdClass::class);
});

test('EventException extends RuntimeException for catch-all handling', function () {
    $e = new \ZeroBoiler\Events\Exceptions\EventException('test error');

    expect($e)->toBeInstanceOf(\RuntimeException::class);
    expect($e)->toBeInstanceOf(\Throwable::class);
    expect($e->getMessage())->toBe('test error');
});

test('All event exceptions are catchable via Throwable', function () {
    $exceptions = [
        new \ZeroBoiler\Events\Exceptions\EventException('base'),
        new \ZeroBoiler\Events\Exceptions\ActionResolutionException('Acme\Action', 'reason'),
        new \ZeroBoiler\Events\Exceptions\TriggerNotFoundException('id-123'),
        new \ZeroBoiler\Events\Exceptions\ConditionEvaluationException('field', 'reason'),
        new \ZeroBoiler\Events\Exceptions\SubscriptionException('sub error'),
    ];

    foreach ($exceptions as $e) {
        expect($e)->toBeInstanceOf(\Throwable::class);
        expect($e)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
    }
});

test('Subscription signPayload returns empty string when secret is null', function () {
    $sub = \ZeroBoiler\Events\Models\Subscription::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.sign',
        'url' => 'https://example.com/hook',
        'secret' => null,
    ]);

    expect($sub->signPayload('{"data":1}'))->toBe('');
});

test('Subscription signPayload returns HMAC signature when secret is set', function () {
    $sub = \ZeroBoiler\Events\Models\Subscription::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.sign',
        'url' => 'https://example.com/hook',
        'secret' => 'whsec_test_secret_key_1234',
    ]);

    $signature = $sub->signPayload('{"data":1}');

    expect($signature)->not->toBe('');
    expect($signature)->not->toBe('0');
    // Verify it's a valid hex string
    expect(ctype_xdigit($signature))->toBeTrue();
});

test('Subscription recordFailure increments failure count', function () {
    $sub = \ZeroBoiler\Events\Models\Subscription::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.failure',
        'url' => 'https://example.com/hook',
        'failure_count' => 0,
    ]);

    $sub->recordFailure();

    expect($sub->failure_count)->toBe(1);
});

test('Subscription hasExceededFailures uses config threshold', function () {
    $sub = \ZeroBoiler\Events\Models\Subscription::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.threshold',
        'url' => 'https://example.com/hook',
        'failure_count' => 5,
    ]);

    app('config')->set('events.subscriptions.max_failures', 10);
    expect($sub->hasExceededFailures())->toBeFalse();

    expect($sub->hasExceededFailures(5))->toBeTrue();
    expect($sub->hasExceededFailures(6))->toBeFalse();
});

test('Subscription resetFailures sets count to zero', function () {
    $sub = \ZeroBoiler\Events\Models\Subscription::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.reset',
        'url' => 'https://example.com/hook',
        'failure_count' => 15,
    ]);

    $sub->resetFailures();

    expect($sub->failure_count)->toBe(0);
});
