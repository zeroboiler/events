<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Phase 183 — Production readiness deep audit.
 *
 * Covers runtime edge cases, type safety, and contract compliance
 * that go beyond static file analysis.
 */
it('ConditionEngine strictEquals returns false for float vs int with different string representations', function (): void {
    $engine = new ConditionEngine();

    // float 1.0 vs int 1: get_debug_type differs (double vs int), both scalar
    // Falls back to string comparison: "1" === "1" → true for identical string reps
    expect($engine->matches(['value' => 1.0], ['value' => 1]))->toBeTrue();

    // float 1.5 vs int 1: string comparison "1.5" !== "1" → false
    expect($engine->matches(['value' => 1.5], ['value' => 1]))->toBeFalse();
});

it('ConditionEngine handles not_contains operator for strings and arrays', function (): void {
    $engine = new ConditionEngine();

    // String not_contains
    expect($engine->matches(
        ['tags' => 'hello world'],
        ['tags' => ['not_contains', 'xyz']],
    ))->toBeTrue();

    expect($engine->matches(
        ['tags' => 'hello world'],
        ['tags' => ['not_contains', 'world']],
    ))->toBeFalse();

    // Array not_contains
    expect($engine->matches(
        ['tags' => ['a', 'b', 'c']],
        ['tags' => ['not_contains', 'x']],
    ))->toBeTrue();

    expect($engine->matches(
        ['tags' => ['a', 'b', 'c']],
        ['tags' => ['not_contains', 'b']],
    ))->toBeFalse();
});

it('ConditionEngine handles not_empty operator for various types', function (): void {
    $engine = new ConditionEngine();

    // Non-empty string
    expect($engine->matches(['name' => 'active'], ['name' => ['not_empty']]))->toBeTrue();
    // Empty string
    expect($engine->matches(['name' => ''], ['name' => ['not_empty']]))->toBeFalse();
    // Non-empty array
    expect($engine->matches(['items' => [1]], ['items' => ['not_empty']]))->toBeTrue();
    // Empty array
    expect($engine->matches(['items' => []], ['items' => ['not_empty']]))->toBeFalse();
    // Null
    expect($engine->matches(['value' => null], ['value' => ['not_empty']]))->toBeFalse();
    // Integer zero (0 is empty in PHP)
    expect($engine->matches(['count' => 0], ['count' => ['not_empty']]))->toBeFalse();
    // Integer non-zero
    expect($engine->matches(['count' => 5], ['count' => ['not_empty']]))->toBeTrue();
});

it('ConditionEngine between operator auto-normalizes inverted ranges', function (): void {
    $engine = new ConditionEngine();

    // Inverted range: [100, 50] should be treated as [50, 100]
    expect($engine->matches(
        ['value' => 75],
        ['value' => ['between', [100, 50]]],
    ))->toBeTrue();

    // Below inverted range
    expect($engine->matches(
        ['value' => 25],
        ['value' => ['between', [100, 50]]],
    ))->toBeFalse();

    // Above inverted range
    expect($engine->matches(
        ['value' => 150],
        ['value' => ['between', [100, 50]]],
    ))->toBeFalse();
});

it('WildcardMatcher findMatchingPatterns returns empty for empty patterns array', function (): void {
    expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
});

it('WildcardMatcher findMatchingPatterns filters correctly', function (): void {
    $patterns = ['order.placed', 'order.*', 'user.created', '*.deleted'];

    $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.shipped');

    expect($result)->toBe(['order.*']);
});

it('WildcardMatcher extractWildcards returns empty for no-wildcard pattern', function (): void {
    // No wildcards in pattern → cannot extract
    expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))->toBe([]);
});

it('WildcardMatcher handles pattern with only dots', function (): void {
    expect(WildcardMatcher::matches('.', '.'))->toBeTrue();
    expect(WildcardMatcher::matches('.', 'a'))->toBeFalse();
});

it('TriggerBuilder save with only actions() method produces correct JSON', function (): void {
    $eventManager = app(EventManager::class);
    $builder = new TriggerBuilder($eventManager);

    $trigger = $builder
        ->on('test.multi')
        ->actions(['ActionOne', 'ActionTwo', 'ActionThree'])
        ->save();

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBe(['ActionOne', 'ActionTwo', 'ActionThree']);
});

it('TriggerBuilder deduplicates actions from both action() and actions()', function (): void {
    $eventManager = app(EventManager::class);
    $builder = new TriggerBuilder($eventManager);

    $trigger = $builder
        ->on('test.dedup')
        ->action('SharedAction')
        ->actions(['SharedAction', 'UniqueAction'])
        ->save();

    $decoded = json_decode($trigger->action, true);
    // resolveActions prepends single action, then deduplicates
    expect($decoded)->toBe(['SharedAction', 'UniqueAction']);
});

it('Subscription signPayload returns empty string for null secret', function (): void {
    $subscription = new Subscription(['secret' => null]);
    expect($subscription->signPayload('test-payload'))->toBe('');
});

it('Subscription signPayload returns empty string for empty secret', function (): void {
    $subscription = new Subscription(['secret' => '']);
    expect($subscription->signPayload('test-payload'))->toBe('');
});

it('Subscription signPayload produces consistent signatures', function (): void {
    $subscription = new Subscription(['secret' => 'whsec_test_secret_1234567890']);

    $sig1 = $subscription->signPayload('same-payload');
    $sig2 = $subscription->signPayload('same-payload');

    expect($sig1)->toBe($sig2);
    expect($sig1)->not->toBe('');
});

it('DomainEvent fromArray handles missing optional keys gracefully', function (): void {
    // Only eventType provided — UUID and timestamp auto-generated
    $event = DomainEvent::fromArray(['eventType' => 'test.created']);

    expect($event->eventType)->toBe('test.created');
    expect($event->payload)->toBe([]);
    expect($event->eventId->toString())->toBeString();
    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

it('DomainEvent fromArray throws for missing eventType', function (): void {
    DomainEvent::fromArray(['payload' => ['key' => 'value']]);
})->throws(InvalidArgumentException::class, 'eventType is required');

it('DomainEvent fromArray handles extra unknown keys without error', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'payload' => ['data' => 1],
        'extraKey' => 'ignored',
        'anotherKey' => 42,
    ]);

    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe(['data' => 1]);
});

it('DomainEvent toArray preserves all fields', function (): void {
    $event = DomainEvent::occur('order.created', ['order_id' => '123']);

    $array = $event->toArray();

    expect($array)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
    expect($array['eventType'])->toBe('order.created');
    expect($array['payload'])->toBe(['order_id' => '123']);
});

it('DomainEvent roundtrip preserves identity', function (): void {
    $original = DomainEvent::occur('roundtrip.test', ['value' => 42]);
    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
});

it('EventLog status constants are all unique strings', function (): void {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    $unique = array_unique($statuses);
    expect($unique)->toBe($statuses);
    expect(count($statuses))->toBe(4);

    foreach ($statuses as $status) {
        expect($status)->toBeString();
        expect($status)->not->toBeEmpty();
    }
});

it('EventLog markAsCompleted updates status and duration', function (): void {
    $trigger = Trigger::create([
        'name' => 'Test',
        'event' => 'test.event',
        'action' => 'TestAction',
        'enabled' => true,
    ]);

    $log = EventLog::create([
        'trigger_id' => $trigger->id,
        'event' => 'test.event',
        'payload' => ['key' => 'value'],
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $log->markAsCompleted(150);
    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBe(150);
});

it('EventLog markAsFailed updates status and error', function (): void {
    $trigger = Trigger::create([
        'name' => 'Test',
        'event' => 'test.event',
        'action' => 'TestAction',
        'enabled' => true,
    ]);

    $log = EventLog::create([
        'trigger_id' => $trigger->id,
        'event' => 'test.event',
        'payload' => ['key' => 'value'],
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $log->markAsFailed('Connection timeout');
    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBe('Connection timeout');
});

it('Trigger scopeEnabled returns only enabled triggers', function (): void {
    Trigger::create([
        'name' => 'Enabled Trigger',
        'event' => 'scope.test',
        'action' => 'TestAction',
        'enabled' => true,
    ]);

    Trigger::create([
        'name' => 'Disabled Trigger',
        'event' => 'scope.test',
        'action' => 'TestAction',
        'enabled' => false,
    ]);

    $enabled = Trigger::enabled()->where('event', 'scope.test')->get();
    expect($enabled->count())->toBe(1);
    expect($enabled->first()->name)->toBe('Enabled Trigger');
});

it('Trigger scopeOrderByPriority sorts by priority descending', function (): void {
    Trigger::create([
        'name' => 'Low Priority',
        'event' => 'priority.test',
        'action' => 'TestAction',
        'priority' => 1,
    ]);

    Trigger::create([
        'name' => 'High Priority',
        'event' => 'priority.test',
        'action' => 'TestAction',
        'priority' => 10,
    ]);

    $sorted = Trigger::where('event', 'priority.test')->orderByPriority()->get();
    expect($sorted->first()->name)->toBe('High Priority');
    expect($sorted->last()->name)->toBe('Low Priority');
});

it('Subscription scopeActive returns only active subscriptions', function (): void {
    Subscription::create([
        'event' => 'sub.test',
        'url' => 'https://example.com/active',
        'active' => true,
        'failure_count' => 0,
        'delivery_count' => 0,
    ]);

    Subscription::create([
        'event' => 'sub.test',
        'url' => 'https://example.com/inactive',
        'active' => false,
        'failure_count' => 15,
        'delivery_count' => 0,
    ]);

    $active = Subscription::active()->where('event', 'sub.test')->get();
    expect($active->count())->toBe(1);
    expect($active->first()->url)->toBe('https://example.com/active');
});

it('Subscription hasExceededFailures uses config threshold', function (): void {
    $subscription = Subscription::create([
        'event' => 'threshold.test',
        'url' => 'https://example.com/hook',
        'active' => true,
        'failure_count' => 5,
        'delivery_count' => 0,
    ]);

    // Default threshold is 10
    expect($subscription->hasExceededFailures())->toBeFalse();

    // Explicit override: 5 is the threshold
    expect($subscription->hasExceededFailures(5))->toBeTrue();
    expect($subscription->hasExceededFailures(6))->toBeFalse();
});

it('Subscription matchesEvent delegates to WildcardMatcher for wildcard patterns', function (): void {
    $subscription = Subscription::create([
        'event' => 'order.*',
        'url' => 'https://example.com/webhook',
        'active' => true,
        'failure_count' => 0,
        'delivery_count' => 0,
    ]);

    expect($subscription->matchesEvent('order.placed'))->toBeTrue();
    expect($subscription->matchesEvent('order.shipped'))->toBeTrue();
    expect($subscription->matchesEvent('order.placed.extra'))->toBeFalse(); // Single * doesn't cross segments
    expect($subscription->matchesEvent('user.created'))->toBeFalse();
});

it('ActionResolver throws for non-existent class', function (): void {
    $resolver = new ActionResolver(app());

    $resolver->resolve('NonExistent\\ActionClass');
})->throws(InvalidArgumentException::class, 'does not exist');

it('ActionResolver throws for class that does not implement Triggerable', function (): void {
    $resolver = new ActionResolver(app());

    $resolver->resolve(stdClass::class);
})->throws(InvalidArgumentException::class, 'must implement');

it('EventManager container method returns the application container', function (): void {
    $manager = app(EventManager::class);
    $container = $manager->container();

    expect($container)->toBe(app());
});

it('EventManager listTriggers with wildcard event filter', function (): void {
    $manager = app(EventManager::class);

    Trigger::create([
        'name' => 'Order Trigger',
        'event' => 'order.placed',
        'action' => 'TestAction',
    ]);

    Trigger::create([
        'name' => 'User Trigger',
        'event' => 'user.created',
        'action' => 'TestAction',
    ]);

    $triggers = $manager->listTriggers('order.*');
    expect($triggers->count())->toBe(1);
    expect($triggers->first()->event)->toBe('order.placed');
});

it('EventManager getTrigger returns null for empty string', function (): void {
    $manager = app(EventManager::class);

    expect($manager->getTrigger(''))->toBeNull();
    expect($manager->getTrigger('0'))->toBeNull();
});

it('EventManager deleteTrigger returns false for non-existent ID', function (): void {
    $manager = app(EventManager::class);

    expect($manager->deleteTrigger('non-existent-uuid'))->toBeFalse();
    expect($manager->deleteTrigger(''))->toBeFalse();
});

it('EventManager enable/disable returns false for empty string ID', function (): void {
    $manager = app(EventManager::class);

    expect($manager->enable(''))->toBeFalse();
    expect($manager->enable('0'))->toBeFalse();
    expect($manager->disable(''))->toBeFalse();
    expect($manager->disable('0'))->toBeFalse();
});

it('ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ConditionEngine();
    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
});

it('WildcardMatcher is readonly final', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

it('ConditionEngine is final', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    expect($ref->isFinal())->toBeTrue();
});

it('EventManager is final with readonly constructor properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);

    expect($ref->isFinal())->toBeTrue();

    $ctor = $ref->getConstructor();
    expect($ctor)->not->toBeNull();

    $params = $ctor->getParameters();
    expect(count($params))->toBe(3);

    // conditionEngine, actionResolver, app
    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue();
        expect($param->isReadOnly())->toBeTrue();
    }
});

it('DispatchTriggerJob reads config at construction time', function (): void {
    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: ['key' => 'value'],
    );

    // Default config values
    expect($job->tries)->toBe(3);
    expect($job->queue)->toBe('default');
    expect($job->backoff)->toBe([60, 300, 900]);
});

it('DispatchTriggerJob has eventLogId as null initially', function (): void {
    $job = new DispatchTriggerJob(
        triggerId: 'test-id',
        event: 'test.event',
        payload: [],
    );

    // Use reflection to check protected property
    $ref = new ReflectionProperty($job, 'eventLogId');
    expect($ref->getValue($job))->toBeNull();
});

it('Subscription recordDelivery atomically increments count and updates timestamp', function (): void {
    $subscription = Subscription::create([
        'event' => 'delivery.test',
        'url' => 'https://example.com/hook',
        'active' => true,
        'failure_count' => 0,
        'delivery_count' => 5,
    ]);

    $subscription->recordDelivery();
    $subscription->refresh();

    expect($subscription->delivery_count)->toBe(6);
    expect($subscription->last_fired_at)->not->toBeNull();
});

it('Subscription resetFailures sets count to zero', function (): void {
    $subscription = Subscription::create([
        'event' => 'reset.test',
        'url' => 'https://example.com/hook',
        'active' => true,
        'failure_count' => 10,
        'delivery_count' => 0,
    ]);

    $subscription->resetFailures();
    $subscription->refresh();

    expect($subscription->failure_count)->toBe(0);
});

it('Source files have consistent namespace declarations', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $errors = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = $file->getContents();

        // Must have declare(strict_types=1)
        if (! str_contains($content, 'declare(strict_types=1)')) {
            $errors[] = $file->getFilename().' missing strict_types';
        }

        // Must have license header
        if (! str_contains($content, 'This file is part of ZeroBoiler')) {
            $errors[] = $file->getFilename().' missing license header';
        }
    }

    expect($errors)->toBeEmpty('Source files with issues: '.implode(', ', $errors));
});

it('All console commands are final with handle method returning int', function (): void {
    $namespace = 'ZeroBoiler\\Events\\Console\\';
    $commandClasses = [
        'EventsDisableCommand',
        'EventsEnableCommand',
        'EventsFireCommand',
        'EventsHealthCommand',
        'EventsListCommand',
        'EventsLogCommand',
        'EventsRedeliverCommand',
        'EventsRegisterCommand',
        'EventsRetryCommand',
        'EventsSubscribeCommand',
        'EventsSubscriptionsCommand',
        'EventsUnsubscribeCommand',
    ];

    foreach ($commandClasses as $class) {
        $fqcn = $namespace.$class;
        $ref = new ReflectionClass($fqcn);

        expect($ref->isFinal())->toBeTrue("{$class} must be final");

        $method = $ref->getMethod('handle');
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull("{$class}::handle() must have return type");
        expect((string) $returnType)->toBe('int', "{$class}::handle() must return int");
    }
});

it('Config file has all required top-level keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect($config)->toBeArray();

    $requiredKeys = [
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Config missing key: {$key}");
    }
});

it('Config table_names has all three tables', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveKeys([
        'triggers',
        'event_logs',
        'subscriptions',
    ]);
});

it('ServiceProvider provides returns exactly 7 bindings', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());

    $provides = $provider->provides();

    expect($provides)->toBe([
        EventManager::class,
        ConditionEngine::class,
        ConditionEngineContract::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        \ZeroBoiler\Events\EventScheduler::class,
    ]);
    expect(count($provides))->toBe(7);
});
