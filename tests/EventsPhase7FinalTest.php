<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

// Load test action classes

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('EventManager::fireModel', function (): void {
    test('fires model event with flattened attributes', function (): void {
        $manager = app(EventManager::class);

        // Register a trigger for the model event
        Trigger::factory()->create([
            'event' => 'App\Models\Order.created',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        // Create a mock model object
        $model = new class
        {
            public function attributesToArray(): array
            {
                return [
                    'id' => 42,
                    'status' => 'active',
                    'total' => 99.99,
                ];
            }
        };

        expect(fn (): mixed => $manager->fireModel('App\Models\Order', 'created', $model))
            ->not->toThrow(\Throwable::class);

        // Verify an EventLog was created with the model data
        expect(EventLog::count())->toBe(1);
        $log = EventLog::first();
        expect($log->event)->toBe('App\Models.Order.created');
        expect($log->payload['id'])->toBe(42);
        expect($log->payload['status'])->toBe('active');
        expect($log->payload['total'])->toBe(99.99);
        expect($log->payload['model_class'])->toBe('App\Models\Order');
        expect($log->payload['action'])->toBe('created');
    });

    test('fires model event using toArray fallback when attributesToArray missing', function (): void {
        $manager = app(EventManager::class);

        Trigger::factory()->create([
            'event' => 'App\Models\User.created',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        $model = new class
        {
            public function toArray(): array
            {
                return ['name' => 'John', 'email' => 'john@example.com'];
            }
        };

        $manager->fireModel('App\Models\User', 'created', $model);

        $log = EventLog::first();
        expect($log->payload['name'])->toBe('John');
        expect($log->payload['email'])->toBe('john@example.com');
    });

    test('fires model event with plain object (no attributes)', function (): void {
        $manager = app(EventManager::class);

        Trigger::factory()->create([
            'event' => 'stdClass.updated',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        $model = new \stdClass;

        $manager->fireModel('stdClass', 'updated', $model);

        $log = EventLog::first();
        expect($log->payload['model_class'])->toBe('stdClass');
        expect($log->payload['action'])->toBe('updated');
        // No flattened attributes since the object has no attributesToArray or toArray
        expect(isset($log->payload['model']))->toBeTrue();
    });
});

describe('WildcardMatcher edge cases', function (): void {
    test('matches pattern with regex special characters escaped', function (): void {
        // Patterns with regex characters like . + should be escaped and treated literally
        expect(WildcardMatcher::matches('user.+created', 'user.+created'))->toBeTrue();
        expect(WildcardMatcher::matches('user.+created', 'user.anythingcreated'))->toBeFalse();
    });

    test('matches pattern with backslash', function (): void {
        expect(WildcardMatcher::matches('ns\\Class.method', 'ns\\Class.method'))->toBeTrue();
    });

    test('extractWildcards returns correct values for multiple single wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created.*', 'user.admin.created.2024');

        expect($result)->toBe(['admin', '2024']);
    });

    test('extractWildcards returns empty when event does not match', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'order.placed');

        expect($result)->toBe([]);
    });

    test('findMatchingPatterns preserves order from input', function (): void {
        $patterns = ['order.shipped', 'order.*', 'order.placed'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe(['order.placed', 'order.*']);
    });
});

describe('DomainEvent edge cases', function (): void {
    test('occur creates event with fresh UUID and current timestamp', function (): void {
        $event = DomainEvent::occur('test.event');

        expect($event->eventId)->not->toBeNull();
        expect($event->eventType)->toBe('test.event');
        expect($event->payload)->toBe([]);
        expect($event->occurredAt)->not->toBeNull();

        // Timestamp should be within 2 seconds of now
        $diff = abs(($event->occurredAt->getTimestamp()) - (new \DateTimeImmutable)->getTimestamp());
        expect($diff)->toBeLessThanOrEqual(2);
    });

    test('occur with explicit eventId and occurredAt preserves them', function (): void {
        $uuid = \Ramsey\Uuid\Uuid::uuid4();
        $time = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $event = new DomainEvent(
            'custom.event',
            ['key' => 'value'],
            $uuid,
            $time,
        );

        expect($event->eventId->toString())->toBe($uuid->toString());
        expect($event->occurredAt)->toEqual($time);
    });

    test('toArray contains all expected keys', function (): void {
        $event = DomainEvent::occur('order.placed', ['order_id' => 123]);
        $data = $event->toArray();

        expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
        expect($data['eventType'])->toBe('order.placed');
        expect($data['payload'])->toBe(['order_id' => 123]);
    });

    test('fromArray with empty array creates event with defaults', function (): void {
        $event = DomainEvent::fromArray([]);

        expect($event->eventType)->toBe('');
        expect($event->payload)->toBe([]);
        expect($event->eventId)->not->toBeNull();
        expect($event->occurredAt)->not->toBeNull();
    });

    test('fromArray with non-string eventType coerces to empty', function (): void {
        $event = DomainEvent::fromArray(['eventType' => 12345]);

        expect($event->eventType)->toBe('');
    });
});

describe('DispatchTriggerJob edge cases', function (): void {
    test('constructor reads backoff from config as comma-separated string', function (): void {
        $app = app();
        $config = $app->get('config');
        $config->set('events.retry.backoff', '10,20,30,60');

        $job = new DispatchTriggerJob('test-id', 'test.event', []);

        expect($job->backoff)->toBe([10, 20, 30, 60]);

        // Restore
        $config->set('events.retry.backoff', '60,300,900');
    });

    test('constructor handles backoff config that is not string gracefully', function (): void {
        $app = app();
        $config = $app->get('config');
        $config->set('events.retry.backoff', 100);

        $job = new DispatchTriggerJob('test-id', 'test.event', []);

        // Non-string backoff config — the cast to string in explode still works
        // but the backoff remains as the default since the condition check guards it
        expect(is_array($job->backoff))->toBeTrue();

        // Restore
        $config->set('events.retry.backoff', '60,300,900');
    });

    test('constructor handles tries config that is not int gracefully', function (): void {
        $app = app();
        $config = $app->get('config');
        $config->set('events.retry.tries', 'five');

        $job = new DispatchTriggerJob('test-id', 'test.event', []);

        // Non-int tries config falls back to default 3
        expect($job->tries)->toBe(3);

        // Restore
        $config->set('events.retry.tries', 3);
    });

    test('constructor handles zero tries config gracefully', function (): void {
        $app = app();
        $config = $app->get('config');
        $config->set('events.retry.tries', 0);

        $job = new DispatchTriggerJob('test-id', 'test.event', []);

        // Zero tries falls back to default 3
        expect($job->tries)->toBe(3);

        // Restore
        $config->set('events.retry.tries', 3);
    });
});

describe('EventManager priority ordering', function (): void {
    test('triggers with same priority are ordered by created_at then id', function (): void {
        $manager = app(EventManager::class);

        // Create triggers with same priority
        $t1 = Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
            'priority' => 10,
        ]);

        // Small delay to ensure different created_at
        usleep(1000);

        $t2 = Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
            'priority' => 10,
        ]);

        // Fire event — both triggers should match
        $manager->fire('order.placed', ['test' => true]);

        // Both should have logs
        $logs = EventLog::where('event', 'order.placed')->get();
        expect($logs)->toHaveCount(2);

        // First log should be for t1 (earlier created_at)
        expect($logs[0]->trigger_id)->toBe($t1->id);
        expect($logs[1]->trigger_id)->toBe($t2->id);
    });
});

describe('ConditionEngine comprehensive operators', function (): void {
    test('not_contains operator with string actual', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['message' => ['not_contains', 'spam']], ['message' => 'hello world']))->toBeTrue();
        expect($engine->matches(['message' => ['not_contains', 'spam']], ['message' => 'hello spam world']))->toBeFalse();
    });

    test('not_contains operator with array actual', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['important', 'urgent']]))->toBeTrue();
        expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['spam', 'urgent']]))->toBeFalse();
    });

    test('not_empty operator', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(['name' => ['not_empty']], ['name' => 'John']))->toBeTrue();
        expect($engine->matches(['name' => ['not_empty']], ['name' => '']))->toBeFalse();
        expect($engine->matches(['name' => ['not_empty']], ['name' => null]))->toBeFalse();
        expect($engine->matches(['tags' => ['not_empty']], ['tags' => ['a']]))->toBeTrue();
        expect($engine->matches(['tags' => ['not_empty']], ['tags' => []]))->toBeFalse();
    });

    test('nested dot notation access', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin', 'name' => 'John']],
        ))->toBeTrue();

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'user']],
        ))->toBeFalse();

        // Missing nested key
        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['name' => 'John']],
        ))->toBeFalse();
    });

    test('triple-nested dot notation access', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        expect($engine->matches(
            ['order.billing.country' => 'US'],
            ['order' => ['billing' => ['country' => 'US', 'total' => 100]]],
        ))->toBeTrue();
    });

    test('between operator auto-normalizes inverted range', function (): void {
        $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

        // Inverted range [100, 50] should auto-normalize to [50, 100]
        expect($engine->matches(
            ['amount' => ['between', [100, 50]]],
            ['amount' => 75],
        ))->toBeTrue();

        expect($engine->matches(
            ['amount' => ['between', [100, 50]]],
            ['amount' => 25],
        ))->toBeFalse();

        expect($engine->matches(
            ['amount' => ['between', [100, 50]]],
            ['amount' => 150],
        ))->toBeFalse();
    });
});
