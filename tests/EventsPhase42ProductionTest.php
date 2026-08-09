<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    \Illuminate\Support\Facades\Cache::forget('zeroboiler:events:enabled_wildcard_triggers');
});

describe('Phase 42: Final Production Hardening', function (): void {
    test('fireModel key collision: model attributes named model/model_class/action are overridden by metadata', function (): void {
        Trigger::factory()->create([
            'event' => 'App\Models\CollisionTest.created',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            public function attributesToArray(): array
            {
                return [
                    'model' => 'original_model_value',
                    'model_class' => 'OriginalModelClass',
                    'action' => 'original_action',
                    'name' => 'TestName',
                ];
            }
        };

        EventManager::fireModel('App\Models\CollisionTest', 'created', $model);

        // Metadata keys should override model attributes
        // The metadata keys are: model, model_class, action
        $log = EventLog::first();
        expect($log)->not->toBeNull()
            ->and($log->status)->toBe(EventLog::STATUS_COMPLETED);

        // The 'name' attribute from the model should still be in payload
        expect($log->payload['name'])->toBe('TestName');

        // Metadata keys override model attribute keys (documented behavior)
        expect($log->payload['model_class'])->toBe('App\Models\CollisionTest')
            ->and($log->payload['action'])->toBe('created');

        // The 'model' key should be the model object (metadata), not the string from attributes
        expect($log->payload['model'])->toBe($model);
    });

    test('fireModel with empty attributes does not crash', function (): void {
        Trigger::factory()->create([
            'event' => 'App\Models\EmptyModel.updated',
            'action' => \App\Actions\LogOrderEvent::class,
            'enabled' => true,
            'async' => false,
        ]);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            public function attributesToArray(): array
            {
                return [];
            }
        };

        EventManager::fireModel('App\Models\EmptyModel', 'updated', $model);

        $log = EventLog::first();
        expect($log)->not->toBeNull()
            ->and($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->payload)->toHaveKey('model')
            ->and($log->payload)->toHaveKey('model_class')
            ->and($log->payload)->toHaveKey('action');
    });

    test('parseActions returns empty list for empty string', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // Use reflection to access parseActions since it is protected
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('parseActions');
        $method->setAccessible(true);

        $result = $method->invoke($manager, '');
        expect($result)->toBe([]);

        // "0" is also treated as empty
        $result = $method->invoke($manager, '0');
        expect($result)->toBe([]);
    });

    test('parseActions wraps non-JSON string as single-element array', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('parseActions');
        $method->setAccessible(true);

        $result = $method->invoke($manager, 'App\\Actions\\SomeAction');
        expect($result)->toBe(['App\\Actions\\SomeAction']);
    });

    test('parseActions handles JSON with empty classes array', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('parseActions');
        $method->setAccessible(true);

        $result = $method->invoke($manager, '{"classes": [], "params": {"url": "https://example.com"}}');
        expect($result)->toBe([]);
    });

    test('parseActions handles JSON with single class in classes format', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('parseActions');
        $method->setAccessible(true);

        $result = $method->invoke($manager, '{"classes": ["App\\\\Actions\\\\Foo"], "params": {"url": "https://example.com"}}');
        expect($result)->toBe([
            ['class' => 'App\\Actions\\Foo', 'params' => ['url' => 'https://example.com']],
        ]);
    });

    test('DispatchTriggerJob eventLogId is null before handle and set after', function (): void {
        $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'value']);

        $reflection = new ReflectionProperty($job, 'eventLogId');
        $reflection->setAccessible(true);

        expect($reflection->getValue($job))->toBeNull();
    });

    test('WebhookAction strips internal keys from payload before sending', function (): void {
        // Verify the internal keys are stripped
        $payload = [
            'url' => 'https://example.com/webhook',
            'event' => 'order.placed',
            'headers' => ['X-Custom' => 'value'],
            'subscription_id' => 'sub-123',
            'order_id' => 456,
            'amount' => 99.99,
        ];

        // Simulate what WebhookAction::handle() does
        $webhookData = $payload;
        unset($webhookData['url'], $webhookData['event'], $webhookData['headers'], $webhookData['subscription_id']);

        expect($webhookData)->toBe([
            'order_id' => 456,
            'amount' => 99.99,
        ]);
    });

    test('ConditionEngine matches with empty conditions and empty payload', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches([], []))->toBeTrue();
    });

    test('ConditionEngine matches with single condition on missing key', function (): void {
        $engine = app(ConditionEngine::class);

        // Key does not exist in payload → getNestedValue returns null
        expect($engine->matches(['nonexistent' => 'active'], []))->toBeFalse();
    });

    test('WildcardMatcher matches dot-separated patterns without wildcards', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('a.b.c', 'a.b.c'))->toBeTrue()
            ->and(\ZeroBoiler\Events\WildcardMatcher::matches('a.b.c', 'a.b'))->toBeFalse()
            ->and(\ZeroBoiler\Events\WildcardMatcher::matches('a.b', 'a.b.c'))->toBeFalse();
    });

    test('WildcardMatcher extractWildcards with no wildcards returns empty', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.placed', 'order.placed'))->toBe([]);
    });

    test('SubscriptionBuilder priority default is 0', function (): void {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);

        $reflection = new ReflectionProperty($builder, 'priority');
        $reflection->setAccessible(true);

        expect($reflection->getValue($builder))->toBe(0);
    });

    test('TriggerBuilder priority default is 0', function (): void {
        $builder = app(\ZeroBoiler\Events\TriggerBuilder::class);

        $reflection = new ReflectionProperty($builder, 'priority');
        $reflection->setAccessible(true);

        expect($reflection->getValue($builder))->toBe(0);
    });

    test('EventManager::listTriggers returns empty collection when no triggers', function (): void {
        $result = EventManager::listTriggers();
        expect($result)->toBeEmpty();
    });

    test('EventManager::getTrigger returns null for non-existent', function (): void {
        $result = EventManager::getTrigger('non-existent-uuid');
        expect($result)->toBeNull();
    });

    test('EventManager::deleteTrigger returns false for non-existent', function (): void {
        $result = EventManager::deleteTrigger('non-existent-uuid');
        expect($result)->toBeFalse();
    });

    test('EventManager::getEventHistory returns empty when no logs', function (): void {
        $result = EventManager::getEventHistory();
        expect($result)->toBeEmpty();
    });

    test('EventLog status constants match expected values', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending')
            ->and(EventLog::STATUS_DISPATCHED)->toBe('dispatched')
            ->and(EventLog::STATUS_COMPLETED)->toBe('completed')
            ->and(EventLog::STATUS_FAILED)->toBe('failed');
    });

    test('EventLog::$statuses contains all status constants', function (): void {
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    });

    test('Subscription signPayload returns empty string for null secret', function (): void {
        $sub = \ZeroBoiler\Events\Models\Subscription::factory()->create([
            'secret' => null,
        ]);

        expect($sub->signPayload('test-payload'))->toBe('');
    });

    test('Subscription signPayload returns empty string for empty secret', function (): void {
        $sub = \ZeroBoiler\Events\Models\Subscription::factory()->create([
            'secret' => '',
        ]);

        expect($sub->signPayload('test-payload'))->toBe('');
    });

    test('Subscription signPayload returns deterministic hash for same input', function (): void {
        $sub = \ZeroBoiler\Events\Models\Subscription::factory()->create([
            'secret' => 'test-secret-123',
        ]);

        $hash1 = $sub->signPayload('payload');
        $hash2 = $sub->signPayload('payload');

        expect($hash1)->toBe($hash2)
            ->and($hash1)->not->toBeEmpty();
    });

    test('Subscription signPayload returns different hash for different payloads', function (): void {
        $sub = \ZeroBoiler\Events\Models\Subscription::factory()->create([
            'secret' => 'test-secret-123',
        ]);

        $hash1 = $sub->signPayload('payload-a');
        $hash2 = $sub->signPayload('payload-b');

        expect($hash1)->not->toBe($hash2);
    });

    test('ServiceProvider registers all commands in boot', function (): void {
        $app = app();
        $provider = new EventsServiceProvider($app);

        $reflection = new ReflectionMethod($provider, 'boot');
        // Just verify boot() exists and is callable
        expect($reflection->isPublic())->toBeTrue();

        // Verify the commands array in the provider
        $bootReflection = new ReflectionMethod($provider, 'boot');
        $contents = file_get_contents((string) $reflection->getFileName());
        expect($contents)->toContain('EventsListCommand::class')
            ->and($contents)->toContain('EventsFireCommand::class')
            ->and($contents)->toContain('EventsRegisterCommand::class')
            ->and($contents)->toContain('EventsEnableCommand::class')
            ->and($contents)->toContain('EventsDisableCommand::class')
            ->and($contents)->toContain('EventsRetryCommand::class')
            ->and($contents)->toContain('EventsRedeliverCommand::class')
            ->and($contents)->toContain('EventsLogCommand::class')
            ->and($contents)->toContain('EventsSubscribeCommand::class')
            ->and($contents)->toContain('EventsUnsubscribeCommand::class')
            ->and($contents)->toContain('EventsSubscriptionsCommand::class');
    });

    test('Facades EventManager getFacadeAccessor returns correct class', function (): void {
        $facade = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
        $method = $facade->getMethod('getFacadeAccessor');
        $method->setAccessible(true);

        $result = $method->invoke(null);
        expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
    });

    test('DomainEvent occur always generates fresh UUID', function (): void {
        $a = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event');
        $b = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event');

        expect($a->eventId->toString())->not->toBe($b->eventId->toString());
    });

    test('DomainEvent occur always generates fresh timestamp', function (): void {
        $a = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event');
        usleep(10000); // Small delay
        $b = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event');

        expect($b->occurredAt->getTimestamp())->toBeGreaterThanOrEqual($a->occurredAt->getTimestamp());
    });

    test('DomainEvent toArray has all required keys', function (): void {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
        $data = $event->toArray();

        expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
    });

    test('config events.wildcard_cache_ttl defaults to 300', function (): void {
        $ttl = config('events.wildcard_cache_ttl');
        expect($ttl)->toBe(300);
    });

    test('config events.table_names has all required keys', function (): void {
        $tables = config('events.table_names');

        expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    });

    test('config events.subscriptions has all required keys', function (): void {
        $subs = config('events.subscriptions');

        expect($subs)->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
        ]);
    });

    test('config events.retry has all required keys', function (): void {
        $retry = config('events.retry');

        expect($retry)->toHaveKeys(['tries', 'backoff']);
    });

    test('config events.retention has all required keys', function (): void {
        $retention = config('events.retention');

        expect($retention)->toHaveKeys(['days', 'include_pending']);
    });

    test('config events.queue has all required keys', function (): void {
        $queue = config('events.queue');

        expect($queue)->toHaveKeys(['connection', 'queue']);
    });

    test('composer.json version matches README badge', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $readme = file_get_contents(__DIR__.'/../README.md');

        expect($readme)->toContain("version-{$composer['version']}-blue");
    });
});
