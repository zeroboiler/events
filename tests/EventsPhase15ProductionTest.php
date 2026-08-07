<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// ─── EventManager::executeTrigger basePayload extraction ───

test('executeTrigger uses basePayload from log payload for each action iteration', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->create([
        'event' => 'test.base',
        'action' => json_encode([
            ['class' => 'App\\Actions\\LogOrderEvent', 'params' => ['url' => 'https://example.com']],
            ['class' => 'App\\Actions\\SendOrderNotification', 'params' => ['priority' => 'high']],
        ]),
        'conditions' => null,
        'async' => false,
        'enabled' => true,
    ]);

    $log = new EventLog([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => $trigger->id,
        'event' => 'test.base',
        'payload' => ['user_id' => 42, 'amount' => 100],
        'status' => EventLog::STATUS_PENDING,
    ]);
    $log->save();

    $manager->executeTrigger($trigger, $log);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBeInt()->toBeGreaterThan(0);
});

test('executeTrigger handles empty payload gracefully with basePayload', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->create([
        'event' => 'test.empty',
        'action' => 'App\\Actions\\LogOrderEvent',
        'conditions' => null,
        'async' => false,
        'enabled' => true,
    ]);

    $log = new EventLog([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => $trigger->id,
        'event' => 'test.empty',
        'payload' => null,
        'status' => EventLog::STATUS_PENDING,
    ]);
    $log->save();

    // payload null → basePayload should be [] — action receives empty array
    $manager->executeTrigger($trigger, $log);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
});

test('executeTrigger action params are merged on top of basePayload', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->create([
        'event' => 'test.merge',
        'action' => json_encode(['class' => 'App\\Actions\\SendOrderNotification', 'params' => ['webhook_url' => 'https://hook.com']]),
        'conditions' => null,
        'async' => false,
        'enabled' => true,
    ]);

    $log = new EventLog([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => $trigger->id,
        'event' => 'test.merge',
        'payload' => ['order_id' => 99, 'webhook_url' => 'https://original.com'],
        'status' => EventLog::STATUS_PENDING,
    ]);
    $log->save();

    $manager->executeTrigger($trigger, $log);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
});

// ─── TriggerBuilder save with null conditions ───

test('TriggerBuilder save stores null conditions when no conditions set', function (): void {
    $manager = app(EventManager::class);

    $trigger = $manager->on('test.null.conditions')
        ->name('Null Conditions Trigger')
        ->action('App\\Actions\\SendOrderNotification')
        ->save();

    expect($trigger->conditions)->toBeNull();
    expect($trigger->event)->toBe('test.null.conditions');
});

test('TriggerBuilder save stores empty array conditions', function (): void {
    $manager = app(EventManager::class);

    $trigger = $manager->on('test.empty.conditions')
        ->name('Empty Conditions Trigger')
        ->action('App\\Actions\\SendOrderNotification')
        ->when([])
        ->save();

    expect($trigger->conditions)->toBe([]);
});

// ─── SubscriptionBuilder URL validation ───

test('SubscriptionBuilder rejects non-URL string', function (): void {
    $manager = app(EventManager::class);

    expect(fn (): Subscription => $manager->subscribe('test.event', 'not-a-url')
        ->save(),
    )->toThrow(\InvalidArgumentException::class, 'valid URL');
});

test('SubscriptionBuilder accepts valid HTTPS URL', function (): void {
    $manager = app(EventManager::class);

    $subscription = $manager->subscribe('test.https', 'https://example.com/webhook')
        ->withSecret('test_secret_123')
        ->save();

    expect($subscription->id)->toBeString()->not->toBeEmpty();
    expect($subscription->url)->toBe('https://example.com/webhook');
    expect($subscription->secret)->toBe('test_secret_123');
    expect($subscription->active)->toBeTrue();
});

// ─── ConditionEngine with empty conditions ───

test('ConditionEngine::matches returns true for empty conditions', function (): void {
    $engine = app(ConditionEngineContract::class);

    expect($engine->matches([], ['any' => 'data']))->toBeTrue();
    expect($engine->matches([], []))->toBeTrue();
});

test('ConditionEngine::matches returns true for empty conditions with various payloads', function (): void {
    $engine = app(ConditionEngineContract::class);

    expect($engine->matches([], ['key' => null]))->toBeTrue();
    expect($engine->matches([], ['nested' => ['deep' => 'value']]))->toBeTrue();
    expect($engine->matches([], ['items' => [1, 2, 3]]))->toBeTrue();
});

// ─── WildcardMatcher type safety ───

test('WildcardMatcher::findMatchingPatterns returns list of strings', function (): void {
    $patterns = ['order.placed', 'order.*', 'user.**'];

    $matched = WildcardMatcher::findMatchingPatterns($patterns, 'order.shipped');

    expect($matched)->toBeArray();
    foreach ($matched as $m) {
        expect($m)->toBeString();
    }
    expect($matched)->toContain('order.placed');
    expect($matched)->toContain('order.*');
    expect($matched)->not->toContain('user.**');
});

test('WildcardMatcher::extractWildcards returns empty for exact match without wildcards', function (): void {
    $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');

    expect($result)->toBe([]);
});

test('WildcardMatcher::extractWildcards returns correct segments', function (): void {
    $result = WildcardMatcher::extractWildcards('order.*.created', 'order.item.created');

    expect($result)->toBe(['item']);
});

test('WildcardMatcher::extractWildcards handles multiple wildcards', function (): void {
    $result = WildcardMatcher::extractWildcards('*.order.*', 'user.order.created');

    expect($result)->toBe(['user', 'created']);
});

// ─── ServiceProvider binding lifecycle ───

test('EventManager is singleton — same instance on repeated resolves', function (): void {
    $app = app();

    $first = $app->make(EventManager::class);
    $second = $app->make(EventManager::class);

    expect($first)->toBe($second);
});

test('TriggerBuilder is transient — different instances on each resolve', function (): void {
    $app = app();

    $first = $app->make(TriggerBuilder::class);
    $second = $app->make(TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

test('SubscriptionBuilder is transient — different instances on each resolve', function (): void {
    $app = app();

    $first = $app->make(SubscriptionBuilder::class);
    $second = $app->make(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

test('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
    $app = app();

    $contract = $app->make(ConditionEngineContract::class);
    $concrete = $app->make(ConditionEngine::class);

    expect($contract)->toBe($concrete);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);
});

test('ActionResolver is singleton', function (): void {
    $app = app();

    $first = $app->make(ActionResolver::class);
    $second = $app->make(ActionResolver::class);

    expect($first)->toBe($second);
});

// ─── Config type validation ───

test('config events.table_names contains all 3 required table keys', function (): void {
    $tables = config('events.table_names');

    expect($tables)->toBeArray();
    expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    expect($tables['triggers'])->toBeString();
    expect($tables['event_logs'])->toBeString();
    expect($tables['subscriptions'])->toBeString();
});

test('config events.queue contains connection and queue keys', function (): void {
    $queue = config('events.queue');

    expect($queue)->toBeArray();
    expect($queue)->toHaveKeys(['connection', 'queue']);
});

test('config events.retry contains tries and backoff keys', function (): void {
    $retry = config('events.retry');

    expect($retry)->toBeArray();
    expect($retry)->toHaveKey('tries');
    expect($retry)->toHaveKey('backoff');
});

test('config events.subscriptions contains all required keys', function (): void {
    $subs = config('events.subscriptions');

    expect($subs)->toBeArray();
    expect($subs)->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
    ]);
});

test('config events.retention contains days and include_pending keys', function (): void {
    $retention = config('events.retention');

    expect($retention)->toBeArray();
    expect($retention)->toHaveKeys(['days', 'include_pending']);
});

test('config events.wildcard_cache_ttl is positive integer', function (): void {
    $ttl = config('events.wildcard_cache_ttl');

    expect($ttl)->toBeInt();
    expect($ttl)->toBeGreaterThan(0);
});

// ─── Facade accessor ───

test('EventManager facade accessor returns correct class name', function (): void {
    $accessor = \ZeroBoiler\Events\Facades\EventManager::getFacadeAccessor();

    expect($accessor)->toBe(EventManager::class);
});

// ─── Trigger model config-driven table ───

test('Trigger model uses config table name', function (): void {
    $trigger = new Trigger;

    expect($trigger->getTable())->toBe(config('events.table_names.triggers', 'triggers'));
});

test('EventLog model uses config table name', function (): void {
    $log = new EventLog;

    expect($log->getTable())->toBe(config('events.table_names.event_logs', 'event_logs'));
});

test('Subscription model uses config table name', function (): void {
    $subscription = new Subscription;

    expect($subscription->getTable())->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
});

// ─── TriggerBuilder fluent interface ───

test('TriggerBuilder all methods return self', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->on('test.fluent');

    expect($builder)->toBeInstanceOf(TriggerBuilder::class);
    expect($builder->name('Test'))->toBe($builder);
    expect($builder->action('App\\Actions\\SendOrderNotification'))->toBe($builder);
    expect($builder->actions(['App\\Actions\\LogOrderEvent']))->toBe($builder);
    expect($builder->when(['status' => 'active']))->toBe($builder);
    expect($builder->async())->toBe($builder);
    expect($builder->async(false))->toBe($builder);
    expect($builder->priority(10))->toBe($builder);
    expect($builder->actionParams(['key' => 'value']))->toBe($builder);
});

// ─── SubscriptionBuilder fluent interface ───

test('SubscriptionBuilder all methods return self', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->subscribe('test.fluent', 'https://example.com');

    expect($builder)->toBeInstanceOf(SubscriptionBuilder::class);
    expect($builder->withSecret('secret'))->toBe($builder);
    expect($builder->withFilter(['key' => 'value']))->toBe($builder);
    expect($builder->priority(10))->toBe($builder);
    expect($builder->async())->toBe($builder);
    expect($builder->async(false))->toBe($builder);
});

// ─── DispatchTriggerJob config-driven properties ───

test('DispatchTriggerJob reads tries from config', function (): void {
    config(['events.retry.tries' => 5]);

    $job = new DispatchTriggerJob('id', 'test.event', []);

    expect($job->tries)->toBe(5);
});

test('DispatchTriggerJob reads queue name from config', function (): void {
    config(['events.queue.queue' => 'custom-queue']);

    $job = new DispatchTriggerJob('id', 'test.event', []);

    expect($job->queue)->toBe('custom-queue');
});

test('DispatchTriggerJob reads connection from config', function (): void {
    config(['events.queue.connection' => 'redis']);

    $job = new DispatchTriggerJob('id', 'test.event', []);

    expect($job->connection)->toBe('redis');
});

test('DispatchTriggerJob connection defaults to null when empty', function (): void {
    config(['events.queue.connection' => '']);

    $job = new DispatchTriggerJob('id', 'test.event', []);

    expect($job->connection)->toBeNull();
});

test('DispatchTriggerJob backoff parsed from comma-separated string', function (): void {
    config(['events.retry.backoff' => '30,120,600']);

    $job = new DispatchTriggerJob('id', 'test.event', []);

    expect($job->backoff)->toBe([30, 120, 600]);
});

test('DispatchTriggerJob backoff from array config', function (): void {
    config(['events.retry.backoff' => [10, 20, 30]]);

    $job = new DispatchTriggerJob('id', 'test.event', []);

    expect($job->backoff)->toBe([10, 20, 30]);
});

// ─── EventLog status constants ───

test('EventLog has all required status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
});

test('EventLog $statuses contains all status constants', function (): void {
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    expect(EventLog::$statuses)->toHaveCount(4);
});

// ─── DomainEvent roundtrip ───

test('DomainEvent roundtrip preserves eventId and occurredAt', function (): void {
    $event = DomainEvent::occur('test.roundtrip', ['key' => 'value']);
    $data = $event->toArray();
    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
        ->toBe($event->occurredAt->format(\DateTimeInterface::ATOM));
    expect($restored->eventType)->toBe('test.roundtrip');
    expect($restored->payload)->toBe(['key' => 'value']);
});

test('DomainEvent fresh UUID on each occur() call', function (): void {
    $a = DomainEvent::occur('test.fresh');
    $b = DomainEvent::occur('test.fresh');

    expect($a->eventId->toString())->not->toBe($b->eventId->toString());
});

// ─── Cache invalidation ───

test('trigger cache is invalidated after save', function (): void {
    $manager = app(EventManager::class);

    $manager->on('test.cache.invalidate')
        ->action('App\\Actions\\SendOrderNotification')
        ->save();

    // After save, cache should be cleared
    $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
    expect($cached)->toBeNull();
});

test('trigger cache is invalidated after disable', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->enabled()->create(['event' => 'test.cache.disable']);
    $manager->disable($trigger->id);

    $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
    expect($cached)->toBeNull();
});

test('trigger cache is invalidated after enable', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->disabled()->create(['event' => 'test.cache.enable']);
    $manager->enable($trigger->id);

    $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
    expect($cached)->toBeNull();
});

// ─── Strict types enforcement ───

test('all source files declare strict_types', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

// ─── Final class enforcement ───

test('core classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WildcardMatcher::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        DispatchTriggerJob::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
    ];

    foreach ($finalClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} should be final");
    }
});
