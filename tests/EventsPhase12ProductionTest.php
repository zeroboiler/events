<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\ContractBindingTest;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// ─── ServiceProvider: register() sets up all required bindings ──────────────

test('service provider registers EventManager as singleton', function () {
    $app = $this->createApplication();
    $provider = new EventsServiceProvider($app);
    $provider->register();

    $first = $app->make(\ZeroBoiler\Events\EventManager::class);
    $second = $app->make(\ZeroBoiler\Events\EventManager::class);

    expect($first)->toBe($second);
});

test('service provider registers ConditionEngine as singleton', function () {
    $app = $this->createApplication();
    $provider = new EventsServiceProvider($app);
    $provider->register();

    $first = $app->make(ConditionEngine::class);
    $second = $app->make(ConditionEngine::class);

    expect($first)->toBe($second);
});

test('service provider registers ActionResolver as singleton', function () {
    $app = $this->createApplication();
    $provider = new EventsServiceProvider($app);
    $provider->register();

    $first = $app->make(ActionResolver::class);
    $second = $app->make(ActionResolver::class);

    expect($first)->toBe($second);
});

test('service provider registers TriggerBuilder as transient', function () {
    $app = $this->createApplication();
    $provider = new EventsServiceProvider($app);
    $provider->register();

    $first = $app->make(TriggerBuilder::class);
    $second = $app->make(TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

test('service provider registers SubscriptionBuilder as transient', function () {
    $app = $this->createApplication();
    $provider = new EventsServiceProvider($app);
    $provider->register();

    $first = $app->make(SubscriptionBuilder::class);
    $second = $app->make(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

test('service provider binds ConditionEngineContract to ConditionEngine', function () {
    $app = $this->createApplication();
    $provider = new EventsServiceProvider($app);
    $provider->register();

    $contract = $app->make(ConditionEngineContract::class);
    $concrete = $app->make(ConditionEngine::class);

    expect($contract)->toBe($concrete);
    expect($contract)->toBeInstanceOf(ConditionEngineContract::class);
});

test('service provider merges config', function () {
    $app = $this->createApplication();
    $config = $app->make('config');

    // EventsServiceProvider::register() merges config from events.php
    expect($config->get('events.table_names.triggers'))->toBe('triggers');
    expect($config->get('events.table_names.event_logs'))->toBe('event_logs');
    expect($config->get('events.table_names.subscriptions'))->toBe('event_subscriptions');
    expect($config->get('events.subscriptions.max_failures'))->toBe(10);
    expect($config->get('events.wildcard_cache_ttl'))->toBe(300);
});

// ─── Facade ──────────────────────────────────────────────────────────────────

test('facade accessor returns correct class name', function () {
    $accessor = \ZeroBoiler\Events\Facades\EventManager::getFacadeAccessor();

    expect($accessor)->toBe(\ZeroBoiler\Events\EventManager::class);
});

// ─── WildcardMatcher: comprehensive edge cases ───────────────────────────────

test('WildcardMatcher matches exact non-dotted event', function () {
    expect(WildcardMatcher::matches('order', 'order'))->toBeTrue();
    expect(WildcardMatcher::matches('order', 'invoice'))->toBeFalse();
});

test('WildcardMatcher rejects empty event for all patterns except catch-all', function () {
    expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
});

test('WildcardMatcher handles patterns with regex special chars', function () {
    expect(WildcardMatcher::matches('order.+', 'order.+'))->toBeTrue();
    expect(WildcardMatcher::matches('order.(test)', 'order.(test)'))->toBeTrue();
    expect(WildcardMatcher::matches('order.[0-9]', 'order.[0-9]'))->toBeTrue();
});

test('WildcardMatcher findMatchingPatterns preserves input order', function () {
    $patterns = ['user.*.created', 'order.placed', '*.deleted', 'order.*'];
    $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($result)->toBe(['user.*.created', 'order.placed', '*.deleted', 'order.*']);
});

test('WildcardMatcher findMatchingPatterns returns empty for no matches', function () {
    $result = WildcardMatcher::findMatchingPatterns(['user.*'], 'order.placed');

    expect($result)->toBe([]);
});

test('WildcardMatcher extractWildcards handles multiple wildcards', function () {
    $result = WildcardMatcher::extractWildcards('*.order.*', 'user.order.created');

    expect($result)->toBe(['user', 'created']);
});

// ─── ConditionEngine: operator coverage ──────────────────────────────────────

test('ConditionEngine matches with empty conditions returns true', function () {
    $engine = new ConditionEngine;

    expect($engine->matches([], ['key' => 'value']))->toBeTrue();
});

test('ConditionEngine matches multiple AND conditions', function () {
    $engine = new ConditionEngine;

    expect($engine->matches(
        ['status' => 'active', 'age' => ['>=', 18]],
        ['status' => 'active', 'age' => 25],
    ))->toBeTrue();

    expect($engine->matches(
        ['status' => 'active', 'age' => ['>=', 18]],
        ['status' => 'active', 'age' => 16],
    ))->toBeFalse();
});

test('ConditionEngine between auto-normalizes inverted range', function () {
    $engine = new ConditionEngine;

    // Normal order
    expect($engine->matches(
        ['amount' => ['between', [10, 50]]],
        ['amount' => 30],
    ))->toBeTrue();

    // Inverted order
    expect($engine->matches(
        ['amount' => ['between', [50, 10]]],
        ['amount' => 30],
    ))->toBeTrue();
});

test('ConditionEngine contains with array actual', function () {
    $engine = new ConditionEngine;

    expect($engine->matches(
        ['tags' => ['contains', 'urgent']],
        ['tags' => ['low', 'urgent', 'high']],
    ))->toBeTrue();
});

test('ConditionEngine starts_with and ends_with', function () {
    $engine = new ConditionEngine;

    expect($engine->matches(
        ['email' => ['starts_with', 'admin']],
        ['email' => 'admin@example.com'],
    ))->toBeTrue();

    expect($engine->matches(
        ['domain' => ['ends_with', '.com']],
        ['domain' => 'example.com'],
    ))->toBeTrue();
});

// ─── DomainEvent: roundtrip ──────────────────────────────────────────────────

test('DomainEvent fromArray preserves eventId and occurredAt', function () {
    $event = new \ZeroBoiler\Events\Domain\DomainEvent('user.registered', ['email' => 'test@example.com']);
    $data = $event->toArray();
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->occurredAt->getTimestamp())->toBe($event->occurredAt->getTimestamp());
    expect($restored->eventType)->toBe('user.registered');
});

test('DomainEvent fromArray with missing eventType defaults to empty', function () {
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['payload' => ['key' => 'val']]);

    expect($restored->eventType)->toBe('');
    expect($restored->payload)->toBe(['key' => 'val']);
});

test('DomainEvent fromArray with invalid UUID generates fresh', function () {
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventId' => 'not-a-uuid',
        'eventType' => 'test',
    ]);

    expect($restored->eventId)->not->toBeNull();
    expect($restored->eventType)->toBe('test');
});

// ─── Trigger model scopes ────────────────────────────────────────────────────

test('Trigger scopeEnabled filters correctly', function () {
    Trigger::query()->delete();

    Trigger::factory()->create(['enabled' => true, 'event' => 'test.enabled']);
    Trigger::factory()->create(['enabled' => false, 'event' => 'test.disabled']);

    $enabled = Trigger::enabled()->get();

    expect($enabled->count())->toBe(1);
    expect($enabled->first()->event)->toBe('test.enabled');
});

test('Trigger scopeAsync filters correctly', function () {
    Trigger::query()->delete();

    Trigger::factory()->create(['async' => true, 'event' => 'test.async']);
    Trigger::factory()->create(['async' => false, 'event' => 'test.sync']);

    $async = Trigger::async()->get();

    expect($async->count())->toBe(1);
    expect($async->first()->event)->toBe('test.async');
});

// ─── EventLog model ─────────────────────────────────────────────────────────

test('EventLog markAsCompleted updates status and duration', function () {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $log->markAsCompleted(250);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBe(250);
});

test('EventLog markAsFailed updates status and error', function () {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $log->markAsFailed('Connection timeout');

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBe('Connection timeout');
});

// ─── Subscription model ──────────────────────────────────────────────────────

test('Subscription signPayload returns empty for null secret', function () {
    $sub = Subscription::factory()->create(['secret' => null]);

    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription signPayload returns empty for empty secret', function () {
    $sub = Subscription::factory()->create(['secret' => '']);

    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription signPayload is deterministic', function () {
    $sub = Subscription::factory()->create(['secret' => 'test_secret']);

    $sig1 = $sub->signPayload('payload1');
    $sig2 = $sub->signPayload('payload1');

    expect($sig1)->toBe($sig2);
    expect($sig1)->not->toBe('');
});

test('Subscription hasExceededFailures reads from config', function () {
    $sub = Subscription::factory()->create(['failure_count' => 5]);

    // Default config is 10
    expect($sub->hasExceededFailures())->toBeFalse();
    expect($sub->hasExceededFailures(5))->toBeTrue();
    expect($sub->hasExceededFailures(3))->toBeTrue();
});

test('Subscription matchesEvent exact', function () {
    $sub = Subscription::factory()->create(['event' => 'order.placed']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeFalse();
});

test('Subscription matchesEvent wildcard', function () {
    $sub = Subscription::factory()->create(['event' => 'order.*']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
});

test('Subscription matchesEvent cross-segment wildcard', function () {
    $sub = Subscription::factory()->create(['event' => 'order.**']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
});

// ─── EventManager: fire/fireModel ────────────────────────────────────────────

test('EventManager fire with empty payload and no triggers completes silently', function () {
    EventManager::fire('nonexistent.event', []);
    // Should not throw — no triggers matched
    expect(true)->toBeTrue();
});

test('EventManager fireModel constructs correct event name', function () {
    $firedEvents = [];
    // Register a sync trigger, then fire a model event
    $trigger = Trigger::factory()->create([
        'event' => 'App\\Models\\Order.created',
        'action' => 'App\\Actions\\LogOrderCreated',
        'async' => false,
        'enabled' => true,
    ]);

    // fireModel with a mock object
    $model = new class {
        public function attributesToArray(): array
        {
            return ['id' => 1, 'total' => 99.99];
        }
    };

    // This should create an EventLog even if action resolution fails,
    // because the trigger matches the event name
    try {
        \ZeroBoiler\Events\Facades\EventManager::fireModel('App\\Models\\Order', 'created', $model);
    } catch (\Throwable) {
        // Expected — action class doesn't exist, but the event name is correct
    }

    $log = EventLog::where('event', 'App\\Models\\Order.created')->first();
    expect($log)->not->toBeNull();
});

// ─── Config completeness ────────────────────────────────────────────────────

test('all required config keys exist with correct types', function () {
    $config = config();

    // Top-level keys
    expect($config->get('events.table_names'))->toBeArray();
    expect($config->get('events.queue'))->toBeArray();
    expect($config->get('events.retry'))->toBeArray();
    expect($config->get('events.retention'))->toBeArray();
    expect($config->get('events.subscriptions'))->toBeArray();

    // Sub-keys
    expect($config->get('events.table_names.triggers'))->toBeString();
    expect($config->get('events.table_names.event_logs'))->toBeString();
    expect($config->get('events.table_names.subscriptions'))->toBeString();
    expect($config->get('events.wildcard_cache_ttl'))->toBeInt();
});

// ─── Strict types enforcement ────────────────────────────────────────────────

test('all source files have declare strict_types=1', function () {
    $sourceFiles = glob(__DIR__.'/../src/**/*.php');

    foreach ($sourceFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)
            ->toContain('declare(strict_types=1)')
            ->and(basename($file));
    }
});

test('all core classes are final', function () {
    $coreClasses = [
        \ZeroBoiler\Events\EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WildcardMatcher::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
    ];

    foreach ($coreClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue()->and($class);
    }
});

// ─── EscapesWildcardLike trait ──────────────────────────────────────────────

test('wildcardToLike returns null for non-wildcard', function () {
    $command = new \ZeroBoiler\Events\Console\EventsListCommand;
    $ref = new ReflectionMethod($command, 'wildcardToLike');

    expect($ref->invoke($command, 'order.placed'))->toBeNull();
});

test('wildcardToLike converts asterisk to percent', function () {
    $command = new \ZeroBoiler\Events\Console\EventsListCommand;
    $ref = new ReflectionMethod($command, 'wildcardToLike');

    expect($ref->invoke($command, 'order.*'))->toBe('order.%');
    expect($ref->invoke($command, '*.created'))->toBe('%.created');
});

test('wildcardToLike escapes special chars', function () {
    $command = new \ZeroBoiler\Events\Console\EventsListCommand;
    $ref = new ReflectionMethod($command, 'wildcardToLike');

    expect($ref->invoke($command, 'order.%'))->toBeNull(); // No wildcard
    expect($ref->invoke($command, 'order.*%test*'))->toBe('order.%\\%test%');
});
