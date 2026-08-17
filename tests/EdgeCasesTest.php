<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

// Load test action classes

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('WildcardMatcher edge cases', function (): void {
    test('findMatchingPatterns returns re-indexed array', function (): void {
        $patterns = ['user.*', 'order.placed', 'invoice.*'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)
            ->toBeArray()
            ->toHaveLength(2)
            ->and(array_keys($result))->toBe([0, 1])
            ->and($result[0])->toBe('order.placed')
            ->and($result[1])->toBe('invoice.*');
    });

    test('findMatchingPatterns returns empty array for no matches', function (): void {
        $patterns = ['user.*', 'invoice.*'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe([])
            ->toHaveLength(0);
    });

    test('extractWildcards returns empty for segment count mismatch', function (): void {
        $result = WildcardMatcher::extractWildcards('order.*', 'order.placed.extra');

        expect($result)->toBe([]);
    });
});

describe('ConditionEngine edge cases', function (): void {
    test('handles not_contains operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['message' => ['not_contains', 'error']], ['message' => 'All good']))
            ->toBeTrue()
            ->and($engine->matches(['message' => ['not_contains', 'error']], ['message' => 'An error occurred']))
            ->toBeFalse();
    });

    test('handles strict equality with === operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['count' => ['===', 5]], ['count' => 5]))
            ->toBeTrue()
            ->and($engine->matches(['count' => ['===', '5']], ['count' => 5]))
            ->toBeFalse();
    });

    test('handles strict inequality with !== operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['count' => ['!==', '5']], ['count' => 5]))
            ->toBeTrue();
    });

    test('between with non-array value returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['between', 'not_an_array']], ['amount' => 250]))
            ->toBeFalse();
    });

    test('between with non-numeric actual returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['between', [100, 500]]], ['amount' => 'abc']))
            ->toBeFalse();
    });

    test('regex with pattern exceeding max length returns false', function (): void {
        $engine = app(ConditionEngine::class);

        $longPattern = '/^' . str_repeat('a', 600) . '$/';

        expect($engine->matches(['code' => ['matches', $longPattern]], ['code' => 'aaa']))
            ->toBeFalse();
    });

    test('regex with catastrophic backtracking pattern is rejected', function (): void {
        $engine = app(ConditionEngine::class);

        // Nested quantifier pattern — should be rejected by safeRegexMatch
        expect($engine->matches(['code' => ['matches', '/(a+)+b/']], ['code' => 'aaab']))
            ->toBeFalse();
    });

    test('cross-type scalar comparison uses string coercion', function (): void {
        $engine = app(ConditionEngine::class);

        // int vs string — both scalar, compared as strings
        expect($engine->matches(['id' => '123'], ['id' => 123]))
            ->toBeTrue();
    });

    test('handles non-scalar comparison returns false', function (): void {
        $engine = app(ConditionEngine::class);

        // Array vs string — not both scalar
        expect($engine->matches(['tags' => 'urgent'], ['tags' => ['urgent']]))
            ->toBeFalse();
    });

    test('empty array condition returns false', function (): void {
        $engine = app(ConditionEngine::class);

        // An empty array as condition value should not match
        expect($engine->matches(['status' => []], ['status' => 'active']))
            ->toBeFalse();
    });
});

describe('EventManager edge cases', function (): void {
    test('fire with empty payload works for triggers without conditions', function (): void {
        Trigger::factory()->create([
            'event' => 'ping',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('ping');

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('fire event with multiple matching triggers dispatches all', function (): void {
        Trigger::factory()->create([
            'event' => 'multi.match',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
            'priority' => 50,
        ]);

        Trigger::factory()->create([
            'event' => 'multi.match',
            'action' => \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class,
            'enabled' => true,
            'async' => false,
            'priority' => 10,
        ]);

        EventManagerFacade::fire('multi.match');

        expect(EventLog::count())->toBe(2);
    });

    test('trigger cache invalidation works', function (): void {
        // Populate the wildcard cache
        Trigger::factory()->create([
            'event' => 'cache.test.*',
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('cache.test.event');
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

        // Invalidate
        EventManagerFacade::invalidateTriggerCache();
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
    });

    test('enable non-existent trigger returns false', function (): void {
        expect(EventManagerFacade::enable('non-existent-id'))->toBeFalse();
    });

    test('disable non-existent trigger returns false', function (): void {
        expect(EventManagerFacade::disable('non-existent-id'))->toBeFalse();
    });

    test('fireModel with object that has attributesToArray', function (): void {
        Trigger::factory()->create([
            'event' => 'TestModel.created',
            'action' => \ZeroBoiler\Events\Tests\Actions\LogOrderCreated::class,
            'enabled' => true,
            'async' => false,
        ]);

        $model = new class
        {
            public $id = 42;
            public $name = 'Test';
            public $status = 'active';

            public function attributesToArray(): array
            {
                return [
                    'id' => $this->id,
                    'name' => $this->name,
                    'status' => $this->status,
                ];
            }
        };

        EventManagerFacade::fireModel('TestModel', 'created', $model);

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->payload['model_class'])->toBe('TestModel')
            ->and(EventLog::first()->payload['action'])->toBe('created')
            ->and(EventLog::first()->payload['name'])->toBe('Test');
    });
});

describe('SubscriptionBuilder edge cases', function (): void {
    test('async subscription creates async trigger', function (): void {
        $subscription = EventManagerFacade::subscribe('async.test', 'https://example.com/hook')
            ->async()
            ->save();

        $trigger = Trigger::where('event', 'async.test')->first();

        expect($trigger)->not->toBeNull()
            ->and($trigger->async)->toBeTrue();
    });

    test('subscription builder with priority', function (): void {
        $subscription = EventManagerFacade::subscribe('priority.test', 'https://example.com/hook')
            ->priority(99)
            ->save();

        expect($subscription->priority)->toBe(99);
    });
});

describe('WebhookAction edge cases', function (): void {
    test('throws exception when url is missing', function (): void {
        $action = new WebhookAction;

        expect(fn () => $action->handle(['event' => 'test']))
            ->toThrow(\InvalidArgumentException::class, 'WebhookAction requires a non-empty "url"');
    });

    test('throws exception when url is not a string', function (): void {
        $action = new WebhookAction;

        expect(fn () => $action->handle(['url' => 12345]))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('throws exception when url is empty string', function (): void {
        $action = new WebhookAction;

        expect(fn () => $action->handle(['url' => '']))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('webhook body excludes internal keys', function (): void {
        Illuminate\Support\Facades\Http::fake([
            'https://example.com/hook' => Illuminate\Http\Response('', 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/hook',
            'subscription_id' => 'sub-123',
            'event' => 'test.event',
            'internal_key' => 'secret_value',
            'public_key' => 'public_value',
        ]);

        Illuminate\Support\Facades\Http::assertSent(function ($request): bool {
            $body = $request->data();
            $data = $body['data'] ?? [];

            // Internal keys should not be in the data
            return ! isset($data['url'])
                && ! isset($data['subscription_id'])
                && ! isset($data['headers'])
                && ! isset($data['event'])
                && isset($data['public_key']);
        });
    });

    test('webhook timeout reads from config', function (): void {
        config()->set('events.subscriptions.timeout', 10);

        $action = new WebhookAction;

        // Access getTimeout via reflection
        $reflection = new ReflectionMethod(WebhookAction::class, 'getTimeout');

        expect($reflection->invoke($action))->toBe(10);
    });

    test('webhook max failures reads from config', function (): void {
        config()->set('events.subscriptions.max_failures', 5);

        $action = new WebhookAction;

        // Access getMaxFailures via reflection
        $reflection = new ReflectionMethod(WebhookAction::class, 'getMaxFailures');

        expect($reflection->invoke($action))->toBe(5);
    });

    test('webhook timeout falls back to default for invalid config', function (): void {
        config()->set('events.subscriptions.timeout', 'invalid');

        $action = new WebhookAction;

        $reflection = new ReflectionMethod(WebhookAction::class, 'getTimeout');

        expect($reflection->invoke($action))->toBe(30);
    });

    test('webhook max failures falls back to default for invalid config', function (): void {
        config()->set('events.subscriptions.max_failures', -5);

        $action = new WebhookAction;

        $reflection = new ReflectionMethod(WebhookAction::class, 'getMaxFailures');

        expect($reflection->invoke($action))->toBe(10);
    });
});
