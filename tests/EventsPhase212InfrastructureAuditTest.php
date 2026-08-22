<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Exceptions\ActionResolutionException;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\Actions\CountingAction;
use ZeroBoiler\Events\Tests\Actions\NullAction;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;

// ---------------------------------------------------------------------------
// 1. ActionResolver: private visibility on final class (PHPStan 9 strict)
// ---------------------------------------------------------------------------

test('ActionResolver constructor uses private readonly Container — final class narrowest visibility', function (): void {
    $reflection = new ReflectionClass(ActionResolver::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);

    $param = $params[0];
    expect($param->getName())->toBe('app');
    expect($param->isPrivate())->toBeTrue('Constructor property on a final class should be private for PHPStan 9');
    expect($param->isPromoted())->toBeTrue();
});

test('ActionResolver resolves valid Triggerable from container', function (): void {
    $app = $this->app;
    $app->bind(NullAction::class, fn (): NullAction => new NullAction);

    $resolver = new ActionResolver($app);
    $result = $resolver->resolve(NullAction::class);

    expect($result)->toBeInstanceOf(Triggerable::class);
    expect($result)->toBeInstanceOf(NullAction::class);
});

test('ActionResolver throws for non-existent class', function (): void {
    $resolver = new ActionResolver($this->app);

    $resolver->resolve('NonExistent\Action\Class');
})->throws(ActionResolutionException::class, "Class does not exist");

test('ActionResolver throws for class that does not implement Triggerable', function (): void {
    $app = $this->app;
    $app->bind(\stdClass::class, fn (): \stdClass => new \stdClass);

    $resolver = new ActionResolver($app);
    $resolver->resolve(\stdClass::class);
})->throws(ActionResolutionException::class, 'Class must implement');

// ---------------------------------------------------------------------------
// 2. fireModel() edge cases: stdClass, toArray-only object
// ---------------------------------------------------------------------------

test('fireModel with stdClass (no attributesToArray/toArray) fires with only metadata keys', function (): void {
    $app = $this->app;
    $action = new CountingAction;
    $app->instance(CountingAction::class, $action);

    $manager = $app->make(\ZeroBoiler\Events\EventManager::class);
    $manager->on('StdClass.created')
        ->action(CountingAction::class)
        ->sync()
        ->name('Test Trigger')
        ->save();

    $obj = new \stdClass;
    $manager->fireModel('StdClass', 'created', $obj);

    expect($action->callCount)->toBe(1);
    $payload = $action->calls[0];
    expect($payload)->toHaveKey('model_class');
    expect($payload['model_class'])->toBe('StdClass');
    expect($payload)->toHaveKey('action');
    expect($payload['action'])->toBe('created');
    expect($payload)->toHaveKey('model');
    expect($payload['model'])->toBe($obj);
});

test('fireModel with object having only toArray() (not attributesToArray) fires with flattened data', function (): void {
    $app = $this->app;
    $action = new CountingAction;
    $app->instance(CountingAction::class, $action);

    $manager = $app->make(\ZeroBoiler\Events\EventManager::class);

    $obj = new class {
        public function toArray(): array
        {
            return ['custom_key' => 'custom_value', 'count' => 42];
        }
    };

    $manager->on(get_class($obj) . '.updated')
        ->action(CountingAction::class)
        ->sync()
        ->name('Test Trigger 2')
        ->save();

    $manager->fireModel(get_class($obj), 'updated', $obj);

    expect($action->callCount)->toBe(1);
    $payload = $action->calls[0];
    expect($payload['custom_key'])->toBe('custom_value');
    expect($payload['count'])->toBe(42);
    expect($payload['model_class'])->toBe(get_class($obj));
    expect($payload['action'])->toBe('updated');
});

// ---------------------------------------------------------------------------
// 3. SubscriptionBuilder with auto_generate_secret: false
// ---------------------------------------------------------------------------

test('SubscriptionBuilder with auto_generate_secret false and no secret saves without secret', function (): void {
    $app = $this->app;
    $config = $app->get('config');
    $config->set('events.subscriptions.auto_generate_secret', false);

    $manager = $app->make(\ZeroBoiler\Events\EventManager::class);
    $subscription = $manager->subscribe('test.event', 'https://example.com/webhook')
        ->save();

    expect($subscription->secret)->toBeNull();
    expect($subscription->event)->toBe('test.event');
    expect($subscription->url)->toBe('https://example.com/webhook');
});

// ---------------------------------------------------------------------------
// 4. TriggerBuilder with multiple actions and deduplication
// ---------------------------------------------------------------------------

test('TriggerBuilder with actions() containing duplicates deduplicates preserving order', function (): void {
    $app = $this->app;
    $manager = $app->make(\ZeroBoiler\Events\EventManager::class);

    $trigger = $manager->on('test.dedup')
        ->actions([
            NullAction::class,
            SendOrderNotification::class,
            NullAction::class,
        ])
        ->sync()
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect(is_array($decoded))->toBeTrue();
    expect($decoded)->toHaveCount(2);
    expect($decoded[0])->toBe(NullAction::class);
    expect($decoded[1])->toBe(SendOrderNotification::class);
});

test('TriggerBuilder with both action() and actions() merges and deduplicates', function (): void {
    $app = $this->app;
    $manager = $app->make(\ZeroBoiler\Events\EventManager::class);

    $trigger = $manager->on('test.merge')
        ->action(NullAction::class)
        ->actions([SendOrderNotification::class, NullAction::class])
        ->sync()
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect(is_array($decoded))->toBeTrue();
    expect($decoded)->toHaveCount(2);
    expect($decoded[0])->toBe(NullAction::class);
    expect($decoded[1])->toBe(SendOrderNotification::class);
});

test('TriggerBuilder with multiple actions and actionParams uses classes key', function (): void {
    $app = $this->app;
    $manager = $app->make(\ZeroBoiler\Events\EventManager::class);

    $trigger = $manager->on('test.multi-params')
        ->actions([NullAction::class, SendOrderNotification::class])
        ->actionParams(['webhook_url' => 'https://example.com'])
        ->sync()
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect(is_array($decoded))->toBeTrue();
    expect($decoded)->toHaveKey('classes');
    expect($decoded['classes'])->toBe([NullAction::class, SendOrderNotification::class]);
    expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com']);
});

// ---------------------------------------------------------------------------
// 5. ConditionEngine simple equality with cross-type coercion
// ---------------------------------------------------------------------------

test('ConditionEngine simple equality coerces int and string to string comparison', function (): void {
    $engine = new \ZeroBoiler\Events\ConditionEngine;

    $result = $engine->matches(
        ['code' => '42'],
        ['code' => 42],
    );
    expect($result)->toBeTrue();

    $result2 = $engine->matches(
        ['code' => 42],
        ['code' => '42'],
    );
    expect($result2)->toBeTrue();

    $result3 = $engine->matches(
        ['code' => '99'],
        ['code' => 42],
    );
    expect($result3)->toBeFalse();
});

test('ConditionEngine returns false for array vs string cross-type equality', function (): void {
    $engine = new \ZeroBoiler\Events\ConditionEngine;

    $result = $engine->matches(
        ['items' => 'not-an-array'],
        ['items' => ['a', 'b']],
    );
    expect($result)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. DomainEvent edge cases
// ---------------------------------------------------------------------------

test('DomainEvent fromArray with non-array payload uses empty array', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'payload' => 'not-an-array',
    ]);

    expect($event->payload)->toBe([]);
});

test('DomainEvent occur factory creates fresh UUID and timestamp', function (): void {
    $event = DomainEvent::occur('test.created', ['key' => 'value']);

    expect($event->eventType)->toBe('test.created');
    expect($event->payload)->toBe(['key' => 'value']);
    expect($event->eventId)->not->toBeNull();
    $diff = $event->occurredAt->getTimestamp() - (new \DateTimeImmutable)->getTimestamp();
    expect(abs($diff))->toBeLessThanOrEqual(2);
});

test('DomainEvent toArray contains all fields', function (): void {
    $event = DomainEvent::occur('order.placed', ['amount' => 99.99]);
    $arr = $event->toArray();

    expect($arr)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
    expect($arr['eventType'])->toBe('order.placed');
    expect($arr['payload'])->toBe(['amount' => 99.99]);
});

// ---------------------------------------------------------------------------
// 7. WildcardMatcher readonly class verification
// ---------------------------------------------------------------------------

test('WildcardMatcher is readonly final class with only static methods', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue('WildcardMatcher must be final');
    expect($reflection->isReadOnly())->toBeTrue('WildcardMatcher must be readonly');

    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    foreach ($methods as $method) {
        expect($method->isStatic())->toBeTrue("WildcardMatcher::{$method->getName()}() must be static");
    }
});

// ---------------------------------------------------------------------------
// 8. Exception hierarchy verification
// ---------------------------------------------------------------------------

test('all events exceptions extend EventException which extends RuntimeException', function (): void {
    $exceptions = [
        \ZeroBoiler\Events\Exceptions\EventException::class,
        \ZeroBoiler\Events\Exceptions\ActionResolutionException::class,
        \ZeroBoiler\Events\Exceptions\ConditionEvaluationException::class,
        \ZeroBoiler\Events\Exceptions\SubscriptionException::class,
        \ZeroBoiler\Events\Exceptions\TriggerNotFoundException::class,
    ];

    foreach ($exceptions as $class) {
        expect($class)->toBeInstanceOf(\Throwable::class);
    }

    expect(is_a(
        \ZeroBoiler\Events\Exceptions\ActionResolutionException::class,
        \ZeroBoiler\Events\Exceptions\EventException::class,
        true,
    ))->toBeTrue();

    expect(is_a(
        \ZeroBoiler\Events\Exceptions\EventException::class,
        \RuntimeException::class,
        true,
    ))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 9. ServiceProvider binding verification
// ---------------------------------------------------------------------------

test('EventsServiceProvider registers all bindings correctly', function (): void {
    $app = $this->app;

    expect($app->isSingleton(\ZeroBoiler\Events\ConditionEngine::class))->toBeTrue();
    expect($app->isSingleton(ConditionEngineContract::class))->toBeTrue();
    expect($app->isSingleton(ActionResolver::class))->toBeTrue();
    expect($app->isSingleton(\ZeroBoiler\Events\EventManager::class))->toBeTrue();
    expect($app->isSingleton(\ZeroBoiler\Events\EventScheduler::class))->toBeTrue();

    expect($app->isSingleton(\ZeroBoiler\Events\TriggerBuilder::class))->toBeFalse();
    expect($app->isSingleton(\ZeroBoiler\Events\SubscriptionBuilder::class))->toBeFalse();
});

test('ConditionEngineContract resolves to ConditionEngine instance', function (): void {
    $app = $this->app;
    $resolved = $app->make(ConditionEngineContract::class);

    expect($resolved)->toBeInstanceOf(\ZeroBoiler\Events\ConditionEngine::class);
});

// ---------------------------------------------------------------------------
// 10. Config completeness — verify all config keys are readable
// ---------------------------------------------------------------------------

test('all config keys are accessible and have expected types', function (): void {
    $config = $this->app->get('config');

    expect($config->has('events.table_names'))->toBeTrue();
    expect($config->has('events.queue'))->toBeTrue();
    expect($config->has('events.retry'))->toBeTrue();
    expect($config->has('events.retention'))->toBeTrue();
    expect($config->has('events.subscriptions'))->toBeTrue();
    expect($config->has('events.disabled'))->toBeTrue();
    expect($config->has('events.wildcard_cache_ttl'))->toBeTrue();

    expect($config->get('events.table_names.triggers'))->toBeString();
    expect($config->get('events.table_names.event_logs'))->toBeString();
    expect($config->get('events.table_names.subscriptions'))->toBeString();
    expect($config->get('events.queue.queue'))->toBeString();
    expect($config->get('events.retry.tries'))->toBeInt();
    expect($config->get('events.retention.days'))->toBeInt();
    expect($config->get('events.subscriptions.timeout'))->toBeInt();
    expect($config->get('events.subscriptions.max_failures'))->toBeInt();
    expect($config->get('events.subscriptions.secret_length'))->toBeInt();
    expect($config->get('events.subscriptions.signature_algorithm'))->toBeString();
    expect($config->get('events.subscriptions.auto_generate_secret'))->toBeBool();
});

// ---------------------------------------------------------------------------
// 11. Model cast verification
// ---------------------------------------------------------------------------

test('Trigger model has expected casts', function (): void {
    $model = new Trigger;
    $casts = $model->casts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('async');
    expect($casts)->toHaveKey('enabled');
    expect($casts)->toHaveKey('priority');
    expect($casts['conditions'])->toBe('array');
    expect($casts['async'])->toBe('boolean');
    expect($casts['enabled'])->toBe('boolean');
    expect($casts['priority'])->toBe('int');
});

test('EventLog model has expected casts', function (): void {
    $model = new EventLog;
    $casts = $model->casts();

    expect($casts)->toHaveKey('payload');
    expect($casts)->toHaveKey('duration_ms');
    expect($casts)->toHaveKey('error');
    expect($casts['payload'])->toBe('array');
    expect($casts['duration_ms'])->toBe('int');
    expect($casts['error'])->toBe('string');
});

test('Subscription model has expected casts', function (): void {
    $model = new Subscription;
    $casts = $model->casts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('priority');
    expect($casts)->toHaveKey('active');
    expect($casts)->toHaveKey('failure_count');
    expect($casts)->toHaveKey('delivery_count');
    expect($casts)->toHaveKey('last_fired_at');
    expect($casts['conditions'])->toBe('array');
    expect($casts['priority'])->toBe('int');
    expect($casts['active'])->toBe('boolean');
    expect($casts['failure_count'])->toBe('int');
    expect($casts['delivery_count'])->toBe('int');
    expect($casts['last_fired_at'])->toBe('datetime');
});

// ---------------------------------------------------------------------------
// 12. WebhookAction missing URL validation
// ---------------------------------------------------------------------------

test('WebhookAction throws on empty URL in payload', function (): void {
    $webhook = new \ZeroBoiler\Events\Actions\WebhookAction;
    $webhook->handle(['url' => '']);
})->throws(\InvalidArgumentException::class, 'non-empty "url"');

test('WebhookAction throws on missing URL in payload', function (): void {
    $webhook = new \ZeroBoiler\Events\Actions\WebhookAction;
    $webhook->handle(['data' => 'something']);
})->throws(\InvalidArgumentException::class, 'non-empty "url"');

test('WebhookAction throws on non-string URL in payload', function (): void {
    $webhook = new \ZeroBoiler\Events\Actions\WebhookAction;
    $webhook->handle(['url' => 12345]);
})->throws(\InvalidArgumentException::class, 'non-empty "url"');

// ---------------------------------------------------------------------------
// 13. Subscription HMAC with different algorithms
// ---------------------------------------------------------------------------

test('Subscription signPayload with sha384 algorithm', function (): void {
    $app = $this->app;
    $config = $app->get('config');
    $config->set('events.subscriptions.signature_algorithm', 'sha384');

    $sub = Subscription::factory()->create([
        'secret' => 'whsec_test_secret_key_for_testing_purpose!',
    ]);

    $signature = $sub->signPayload('test-payload');
    expect($signature)->not->toBeEmpty();

    $expected = hash_hmac('sha384', 'test-payload', 'whsec_test_secret_key_for_testing_purpose!');
    expect($signature)->toBe($expected);
});

test('Subscription signPayload with null secret returns empty string', function (): void {
    $sub = Subscription::factory()->withoutSecret()->create();
    $signature = $sub->signPayload('test-payload');
    expect($signature)->toBe('');
});

test('Subscription signPayload with empty secret returns empty string', function (): void {
    $sub = Subscription::factory()->create(['secret' => '']);
    $signature = $sub->signPayload('test-payload');
    expect($signature)->toBe('');
});

// ---------------------------------------------------------------------------
// 14. Facade accessor correctness
// ---------------------------------------------------------------------------

test('EventManager facade accessor returns correct class name', function (): void {
    $facade = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $facade->getMethod('getFacadeAccessor');
    $result = $method->invoke(null);

    expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('EventManager facade is final', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    expect($reflection->isFinal())->toBeTrue();
});
