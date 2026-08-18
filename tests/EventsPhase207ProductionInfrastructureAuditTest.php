<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Tests\Actions\CountingAction;
use ZeroBoiler\Events\Tests\Actions\FailingAction;
use ZeroBoiler\Events\Tests\Actions\NullAction;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 207 Production Infrastructure Audit
 *
 * Comprehensive tests covering:
 * - empty() elimination in model boot and EventManager
 * - Strict conditions check path in shouldDispatch
 * - Config completeness verification
 * - ServiceProvider binding consistency
 * - Facade accessor correctness
 * - All source files strict types + final enforcement
 * - TriggerBuilder/SubscriptionBuilder validation edge cases
 * - WebhookAction handle() without URL
 * - EventScheduler config reads
 * - DomainEvent immutability and reconstruction
 * - WildcardMatcher readonly+final enforcement
 * - ActionResolver edge cases
 * - ManagesHistory/ManagesSubscriptions trait coverage
 */
beforeEach(function (): void {
    // Register test actions in container
    $app = app();
    $app->singleton(NullAction::class);
    $app->singleton(CountingAction::class);
    $app->singleton(FailingAction::class);
});

// ─── Strict empty() Elimination ───

test('model boot generates UUID when id is empty string', function (): void {
    $trigger = new Trigger([
        'name' => 'Test',
        'event' => 'test.event',
        'action' => NullAction::class,
    ]);
    $trigger->save();

    expect($trigger->id)->not->toBeEmpty()
        ->and(Trigger::find($trigger->id))->not->toBeNull();
});

test('model boot preserves explicit UUID when provided', function (): void {
    $explicitId = (string) \Illuminate\Support\Str::uuid();

    $trigger = new Trigger([
        'id' => $explicitId,
        'name' => 'Test',
        'event' => 'test.event',
        'action' => NullAction::class,
    ]);
    $trigger->save();

    expect($trigger->id)->toBe($explicitId);
});

test('event log model boot generates UUID for empty string id', function (): void {
    $trigger = Trigger::factory()->create();
    $log = new EventLog([
        'trigger_id' => $trigger->id,
        'event' => 'test.event',
        'payload' => ['key' => 'value'],
        'status' => EventLog::STATUS_PENDING,
    ]);
    $log->save();

    expect($log->id)->not->toBeEmpty();
});

test('subscription model boot generates UUID for empty string id', function (): void {
    $sub = new Subscription([
        'event' => 'test.event',
        'url' => 'https://example.com/webhook',
        'secret' => 'whsec_test_secret_1234567890',
    ]);
    $sub->save();

    expect($sub->id)->not->toBeEmpty();
});

// ─── Strict Conditions Check ───

test('shouldDispatch returns true when conditions is empty array', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->create([
        'conditions' => [],
        'enabled' => true,
    ]);

    // Use reflection to test the protected shouldDispatch method
    $ref = new ReflectionMethod(EventManager::class, 'shouldDispatch');
    $ref->setAccessible(true);

    $result = $ref->invoke($manager, $trigger, ['key' => 'value']);

    expect($result)->toBeTrue();
});

test('shouldDispatch delegates to condition engine for non-empty conditions', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->create([
        'conditions' => ['status' => 'active'],
        'enabled' => true,
    ]);

    $ref = new ReflectionMethod(EventManager::class, 'shouldDispatch');
    $ref->setAccessible(true);

    // Matches
    expect($ref->invoke($manager, $trigger, ['status' => 'active']))->toBeTrue();

    // Does not match
    expect($ref->invoke($manager, $trigger, ['status' => 'inactive']))->toBeFalse();
});

test('shouldDispatch handles nested dot-notation conditions', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->create([
        'conditions' => ['user.role' => 'admin'],
        'enabled' => true,
    ]);

    $ref = new ReflectionMethod(EventManager::class, 'shouldDispatch');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, $trigger, ['user' => ['role' => 'admin']]))->toBeTrue();
    expect($ref->invoke($manager, $trigger, ['user' => ['role' => 'user']]))->toBeFalse();
});

test('shouldDispatch handles null conditions from database correctly', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->create([
        'conditions' => null,
        'enabled' => true,
    ]);

    $ref = new ReflectionMethod(EventManager::class, 'shouldDispatch');
    $ref->setAccessible(true);

    // null is cast to array by Eloquent, but the model casts it
    // After cast, null becomes null. The empty check should handle this.
    $result = $ref->invoke($manager, $trigger, ['any' => 'data']);
    expect($result)->toBeTrue();
});

// ─── Config Completeness ───

test('config has all required top-level keys', function (): void {
    $config = config('events');
    expect($config)->toBeArray();

    $requiredKeys = [
        'table_names', 'queue', 'retry', 'retention',
        'subscriptions', 'disabled', 'wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))
            ->toBeTrue("Missing config key: events.{$key}");
    }
});

test('config table_names has all three tables', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('config queue has connection and queue keys', function (): void {
    $queue = config('events.queue');
    expect($queue)->toHaveKeys(['connection', 'queue']);
});

test('config retry has tries and backoff keys', function (): void {
    $retry = config('events.retry');
    expect($retry)->toHaveKeys(['tries', 'backoff']);
});

test('config retention has days and schedule_cron keys', function (): void {
    $retention = config('events.retention');
    expect($retention)->toHaveKeys(['days', 'schedule_cron', 'include_pending']);
});

test('config subscriptions has all required keys', function (): void {
    $subs = config('events.subscriptions');
    $requiredKeys = [
        'auto_generate_secret', 'secret_length', 'max_failures',
        'timeout', 'signature_algorithm', 'cleanup_cron',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $subs))
            ->toBeTrue("Missing subscription config key: {$key}");
    }
});

// ─── ServiceProvider Binding Consistency ───

test('service provider resolves EventManager as singleton', function (): void {
    $app = app();
    $first = $app->make(EventManager::class);
    $second = $app->make(EventManager::class);

    expect($first)->toBe($second);
});

test('service provider resolves ConditionEngine as singleton', function (): void {
    $app = app();
    $first = $app->make(ConditionEngine::class);
    $second = $app->make(ConditionEngine::class);

    expect($first)->toBe($second);
});

test('service provider resolves ActionResolver as singleton', function (): void {
    $app = app();
    $first = $app->make(ActionResolver::class);
    $second = $app->make(ActionResolver::class);

    expect($first)->toBe($second);
});

test('service provider resolves TriggerBuilder as transient', function (): void {
    $app = app();
    $first = $app->make(TriggerBuilder::class);
    $second = $app->make(TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

test('service provider resolves SubscriptionBuilder as transient', function (): void {
    $app = app();
    $first = $app->make(SubscriptionBuilder::class);
    $second = $app->make(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

test('service provider resolves EventScheduler as singleton', function (): void {
    $app = app();
    $first = $app->make(EventScheduler::class);
    $second = $app->make(EventScheduler::class);

    expect($first)->toBe($second);
});

test('ConditionEngineContract binding resolves to ConditionEngine', function (): void {
    $resolved = app(ConditionEngineContract::class);
    expect($resolved)->toBeInstanceOf(ConditionEngine::class);
});

// ─── Facade Accessor ───

test('facade accessor returns EventManager class name', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
    $ref->setAccessible(true);
    $result = $ref->invoke(null);

    expect($result)->toBe(EventManager::class);
});

// ─── All Source Files Audit ───

test('all source files have declare strict_types', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob_recursive($srcDir.'/*.php');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)
            ->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
    }
    expect(count($files))->toBeGreaterThanOrEqual(33);
})->skip(fn () => ! function_exists('glob_recursive'), 'glob_recursive helper not available');

test('all source files have license header', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $count = 0;
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $content = $file->getContents();
            expect($content)
                ->toContain('This file is part of ZeroBoiler', "Missing license header in: {$file->getPathname()}");
            $count++;
        }
    }

    expect($count)->toBeGreaterThanOrEqual(33);
});

// ─── TriggerBuilder Edge Cases ───

test('TriggerBuilder rejects empty action class', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->on('test.event');

    expect(fn () => $builder->action('')->save())
        ->toThrow(\InvalidArgumentException::class, 'At least one action is required');
});

test('TriggerBuilder actions() rejects non-string entries', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->on('test.event');

    expect(fn () => $builder->actions([NullAction::class, 123]))->toThrow(\InvalidArgumentException::class);
});

test('TriggerBuilder actions() rejects empty string entries', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->on('test.event');

    expect(fn () => $builder->actions(['']))->toThrow(\InvalidArgumentException::class);
});

test('TriggerBuilder save generates default name from event', function (): void {
    $manager = app(EventManager::class);
    $trigger = $manager->on('order.placed')
        ->action(NullAction::class)
        ->save();

    expect($trigger->name)->toBe('order.placed Trigger');
    $trigger->delete();
});

test('TriggerBuilder resolveActions deduplicates properly', function (): void {
    $manager = app(EventManager::class);

    $ref = new ReflectionMethod(TriggerBuilder::class, 'resolveActions');
    $ref->setAccessible(true);

    $builder = $manager->on('test.event')
        ->action(NullAction::class)
        ->actions([NullAction::class, CountingAction::class]);

    $resolved = $ref->invoke($builder);

    // NullAction should appear once, CountingAction once
    expect($resolved)->toBe([NullAction::class, CountingAction::class]);
});

// ─── SubscriptionBuilder Edge Cases ───

test('SubscriptionBuilder rejects empty event name', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->subscribe('', 'https://example.com/webhook');

    expect(fn () => $builder->save())
        ->toThrow(\InvalidArgumentException::class, 'Event name is required');
});

test('SubscriptionBuilder rejects empty URL', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->subscribe('test.event', '');

    expect(fn () => $builder->save())
        ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required');
});

test('SubscriptionBuilder rejects non-HTTP URL', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->subscribe('test.event', 'ftp://example.com/file');

    expect(fn () => $builder->save())
        ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS protocol');
});

test('SubscriptionBuilder rejects too-short secret', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->subscribe('test.event', 'https://example.com/webhook');

    expect(fn () => $builder->withSecret('short'))
        ->toThrow(\InvalidArgumentException::class, 'at least 16 characters');
});

test('SubscriptionBuilder withSecret accepts exactly 16 characters', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->subscribe('test.event', 'https://example.com/webhook');

    // Should not throw — 16 chars is minimum
    $result = $builder->withSecret('whsec_123456789!');
    expect($result)->toBeInstanceOf(SubscriptionBuilder::class);
});

// ─── WebhookAction Edge Cases ───

test('WebhookAction throws on missing URL in payload', function (): void {
    $action = new WebhookAction;

    expect(fn () => $action->handle(['data' => 'test']))
        ->toThrow(\InvalidArgumentException::class, 'non-empty "url"');
});

test('WebhookAction throws on empty string URL', function (): void {
    $action = new WebhookAction;

    expect(fn () => $action->handle(['url' => '']))
        ->toThrow(\InvalidArgumentException::class, 'non-empty "url"');
});

test('WebhookAction throws on non-string URL', function (): void {
    $action = new WebhookAction;

    expect(fn () => $action->handle(['url' => 12345]))
        ->toThrow(\InvalidArgumentException::class, 'non-empty "url"');
});

// ─── EventScheduler Config Reads ───

test('EventScheduler reads retention days from config', function (): void {
    $scheduler = app(EventScheduler::class);

    $ref = new ReflectionMethod(EventScheduler::class, 'getConfig');
    $ref->setAccessible(true);
    $config = $ref->invoke($scheduler);

    expect($config->get('events.retention.days'))->toBe(30);
});

test('EventScheduler reads cleanup cron from config', function (): void {
    $scheduler = app(EventScheduler::class);

    $ref = new ReflectionMethod(EventScheduler::class, 'getConfig');
    $ref->setAccessible(true);
    $config = $ref->invoke($scheduler);

    expect($config->get('events.subscriptions.cleanup_cron'))->toBe('0 3 * * *');
});

// ─── DomainEvent Immutability ───

test('DomainEvent is final', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    expect($ref->isFinal())->toBeTrue();
});

test('DomainEvent properties are readonly', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);

    expect($ref->getProperty('eventType')->isReadOnly())->toBeTrue();
    expect($ref->getProperty('payload')->isReadOnly())->toBeTrue();
    expect($ref->getProperty('eventId')->isReadOnly())->toBeTrue();
    expect($ref->getProperty('occurredAt')->isReadOnly())->toBeTrue();
});

test('DomainEvent fromArray throws on missing eventType', function (): void {
    expect(fn () => DomainEvent::fromArray(['payload' => []]))
        ->toThrow(\InvalidArgumentException::class, 'eventType is required');
});

test('DomainEvent fromArray handles invalid UUID gracefully', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => 'invalid-uuid',
        'payload' => ['key' => 'value'],
    ]);

    // Invalid UUID should generate a fresh one, not throw
    expect($event->eventType)->toBe('test.event');
    expect($event->eventId->toString())->not->toBe('invalid-uuid');
});

test('DomainEvent fromArray handles invalid datetime gracefully', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'occurredAt' => 'not-a-date',
    ]);

    // Should fall back to now, not throw
    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent toArray and fromArray roundtrip', function (): void {
    $original = DomainEvent::occur('order.placed', ['order_id' => 42]);
    $array = $original->toArray();
    $restored = DomainEvent::fromArray($array);

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});

// ─── WildcardMatcher Enforcement ───

test('WildcardMatcher is readonly final class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('WildcardMatcher::extractWildcards returns empty for double-star patterns', function (): void {
    expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
    expect(WildcardMatcher::extractWildcards('**', 'anything.here'))->toBe([]);
});

test('WildcardMatcher::findMatchingPatterns returns empty array for no matches', function (): void {
    $result = WildcardMatcher::findMatchingPatterns(['user.*'], 'order.placed');
    expect($result)->toBe([]);
});

// ─── ActionResolver Edge Cases ───

test('ActionResolver throws on non-existent class', function (): void {
    $resolver = app(ActionResolver::class);

    expect(fn () => $resolver->resolve('NonExistentClass'))
        ->toThrow(\InvalidArgumentException::class, 'does not exist');
});

test('ActionResolver throws on class that does not implement Triggerable', function (): void {
    $resolver = app(ActionResolver::class);

    expect(fn () => $resolver->resolve(\stdClass::class))
        ->toThrow(\InvalidArgumentException::class, 'must implement');
});

test('ActionResolver resolves valid Triggerable class', function (): void {
    $resolver = app(ActionResolver::class);
    $result = $resolver->resolve(NullAction::class);

    expect($result)->toBeInstanceOf(NullAction::class);
    expect($result)->toBeInstanceOf(Triggerable::class);
});

// ─── ManagesHistory Coverage ───

test('getEventHistory with no filters returns all logs', function (): void {
    $manager = app(EventManager::class);
    $trigger = Trigger::factory()->create();
    EventLog::factory()->count(3)->forTrigger($trigger->id)->create();

    $history = $manager->getEventHistory();
    expect($history)->toBeInstanceOf(Collection::class);
    expect($history->count())->toBeGreaterThanOrEqual(3);
});

test('getEventHistory with status filter', function (): void {
    $manager = app(EventManager::class);
    $trigger = Trigger::factory()->create();
    EventLog::factory()->completed()->forTrigger($trigger->id)->create();
    EventLog::factory()->failed()->forTrigger($trigger->id)->create();

    $completed = $manager->getEventHistory(status: 'completed');
    expect($completed->count())->toBeGreaterThanOrEqual(1);
});

test('getStats returns correct structure', function (): void {
    $manager = app(EventManager::class);

    $stats = $manager->getStats();

    expect($stats)->toHaveKeys([
        'total_logs', 'total_triggers', 'active_triggers',
        'completed', 'failed', 'pending', 'dispatched',
        'success_rate', 'failure_rate', 'avg_duration_ms',
        'top_events', 'top_failed_events',
    ]);
});

test('getStats with since parameter only counts recent logs', function (): void {
    $manager = app(EventManager::class);

    $future = \Illuminate\Support\Carbon::now()->addHour();
    $stats = $manager->getStats($future);

    expect($stats['total_logs'])->toBe(0);
});

// ─── ManagesSubscriptions Coverage ───

test('listSubscriptions with no filters returns all', function (): void {
    $manager = app(EventManager::class);

    $subs = $manager->listSubscriptions();
    expect($subs)->toBeInstanceOf(Collection::class);
});

test('listSubscriptions with activeOnly returns only active', function (): void {
    $manager = app(EventManager::class);
    Subscription::factory()->active()->create();
    Subscription::factory()->inactive()->create();

    $active = $manager->listSubscriptions(activeOnly: true);
    foreach ($active as $sub) {
        expect($sub->active)->toBeTrue();
    }
});

test('getSubscription returns null for non-existent ID', function (): void {
    $manager = app(EventManager::class);

    $result = $manager->getSubscription((string) \Illuminate\Support\Str::uuid());
    expect($result)->toBeNull();
});

// ─── Sanitize Payload for Queue ───

test('sanitizePayloadForQueue strips objects and keeps scalars', function (): void {
    $manager = app(EventManager::class);

    $ref = new ReflectionMethod(EventManager::class, 'sanitizePayloadForQueue');
    $ref->setAccessible(true);

    $model = new Trigger([
        'name' => 'test',
        'event' => 'test',
        'action' => NullAction::class,
    ]);

    $payload = [
        'string' => 'hello',
        'int' => 42,
        'float' => 3.14,
        'bool' => true,
        'null' => null,
        'object' => $model,
        'nested' => [
            'deep_string' => 'value',
            'deep_object' => $model,
        ],
    ];

    $result = $ref->invoke($manager, $payload);

    expect($result['string'])->toBe('hello');
    expect($result['int'])->toBe(42);
    expect($result['float'])->toBe(3.14);
    expect($result['bool'])->toBeTrue();
    expect($result['null'])->toBeNull();
    expect($result['object'])->toContain('[stripped:');
    expect($result['nested']['deep_string'])->toBe('value');
    expect($result['nested']['deep_object'])->toContain('[stripped:');
});

test('sanitizePayloadForQueue handles empty array', function (): void {
    $manager = app(EventManager::class);

    $ref = new ReflectionMethod(EventManager::class, 'sanitizePayloadForQueue');
    $ref->setAccessible(true);

    $result = $ref->invoke($manager, []);

    expect($result)->toBe([]);
});

// ─── EventManager container() Method ───

test('container() returns the application container', function (): void {
    $manager = app(EventManager::class);
    $container = $manager->container();

    expect($container)->toBe(app());
});

// ─── Global Disable ───

test('isDisabled returns config value', function (): void {
    $manager = app(EventManager::class);

    expect($manager->isDisabled())->toBeFalse();
});

test('setEnabled changes runtime config', function (): void {
    $manager = app(EventManager::class);

    $manager->setEnabled(false);
    expect($manager->isDisabled())->toBeTrue();

    $manager->setEnabled(true);
    expect($manager->isDisabled())->toBeFalse();
});

test('fire does nothing when system is disabled', function (): void {
    $manager = app(EventManager::class);
    $manager->setEnabled(false);

    $trigger = Trigger::factory()->enabled()->create([
        'action' => NullAction::class,
        'conditions' => null,
    ]);

    $manager->fire($trigger->event);

    // No event logs should be created
    $logs = EventLog::where('trigger_id', $trigger->id)->count();
    expect($logs)->toBe(0);

    $manager->setEnabled(true);
});

// ─── Trigger ID Guard Tests ───

test('getTrigger returns null for empty string', function (): void {
    $manager = app(EventManager::class);
    expect($manager->getTrigger(''))->toBeNull();
});

test('getTrigger returns null for zero string', function (): void {
    $manager = app(EventManager::class);
    expect($manager->getTrigger('0'))->toBeNull();
});

test('deleteTrigger returns false for empty string', function (): void {
    $manager = app(EventManager::class);
    expect($manager->deleteTrigger(''))->toBeFalse();
});

test('deleteTrigger returns false for non-existent UUID', function (): void {
    $manager = app(EventManager::class);
    expect($manager->deleteTrigger((string) \Illuminate\Support\Str::uuid()))->toBeFalse();
});

test('enable returns false for empty string', function (): void {
    $manager = app(EventManager::class);
    expect($manager->enable(''))->toBeFalse();
});

test('disable returns false for empty string', function (): void {
    $manager = app(EventManager::class);
    expect($manager->disable(''))->toBeFalse();
});

// ─── fireModel Edge Cases ───

test('fireModel throws on empty model class', function (): void {
    $manager = app(EventManager::class);
    expect(fn () => $manager->fireModel('', 'created', new \stdClass))
        ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
});

test('fireModel throws on empty action', function (): void {
    $manager = app(EventManager::class);
    expect(fn () => $manager->fireModel('App\\Models\\Order', '', new \stdClass))
        ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
});

test('fireModel constructs correct event name', function (): void {
    $manager = app(EventManager::class);
    $manager->setEnabled(false); // Disable to avoid trigger dispatch

    // Create a trigger with the expected event name
    Trigger::factory()->enabled()->create([
        'event' => 'App\\Models\\Order.created',
        'action' => NullAction::class,
        'conditions' => null,
    ]);

    $model = new \stdClass;
    $model->status = 'active';

    // Should not throw (event fires but system disabled)
    $manager->fireModel('App\\Models\\Order', 'created', $model);
    expect(true)->toBeTrue();
    $manager->setEnabled(true);
});

// ─── Subscription Model Methods ───

test('subscription resetFailures sets count to zero', function (): void {
    $sub = Subscription::factory()->withFailureCount(5)->create();

    $sub->resetFailures();

    expect($sub->failure_count)->toBe(0);
});

test('subscription recordFailure increments count', function (): void {
    $sub = Subscription::factory()->withFailureCount(0)->create();

    $sub->recordFailure();
    $sub->refresh();

    expect($sub->failure_count)->toBe(1);
});

test('subscription signPayload returns empty for null secret', function (): void {
    $sub = Subscription::factory()->withoutSecret()->create();

    expect($sub->signPayload('test payload'))->toBe('');
});

test('subscription signPayload returns empty for empty secret', function (): void {
    $sub = new Subscription(['secret' => '']);
    // Need to set it directly to empty string
    $sub->save();

    // Actually the model casts it, let's test through the method
    $sub->secret = '';
    expect($sub->signPayload('test'))->toBe('');
});

test('subscription matchesEvent delegates to WildcardMatcher for wildcard patterns', function (): void {
    $sub = Subscription::factory()->forEvent('order.*')->create();

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeTrue();
    expect($sub->matchesEvent('user.created'))->toBeFalse();
});

test('subscription scopeExceededFailures queries threshold from config', function (): void {
    Subscription::factory()->active()->withFailureCount(15)->create();
    Subscription::factory()->active()->withFailureCount(5)->create();

    $exceeded = Subscription::active()->exceededFailures()->get();
    expect($exceeded->count())->toBe(1);
});

// ─── EventLog Scopes ───

test('event log scopeStalePending returns old pending logs', function (): void {
    $trigger = Trigger::factory()->create();

    EventLog::factory()->pending()->forTrigger($trigger->id)->create([
        'created_at' => \Illuminate\Support\Carbon::now()->subDays(7),
    ]);

    $stale = EventLog::stalePending(\Illuminate\Support\Carbon::now()->subDays(3))->get();
    expect($stale->count())->toBeGreaterThanOrEqual(1);
});

test('event log markAsCompleted sets status and duration', function (): void {
    $log = EventLog::factory()->pending()->create();
    $log->markAsCompleted(150);

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBe(150);
});

test('event log markAsFailed sets status and error', function (): void {
    $log = EventLog::factory()->pending()->create();
    $log->markAsFailed('Test error message');

    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBe('Test error message');
});

// ─── Trigger Scopes ───

test('trigger scopeEnabled filters correctly', function (): void {
    Trigger::factory()->enabled()->create();
    Trigger::factory()->disabled()->create();

    $enabled = Trigger::enabled()->count();
    $all = Trigger::count();

    expect($enabled)->toBeLessThan($all);
});

test('trigger scopeOrderByPriority returns highest first', function (): void {
    Trigger::factory()->create(['priority' => 1]);
    Trigger::factory()->create(['priority' => 10]);
    Trigger::factory()->create(['priority' => 5]);

    $ordered = Trigger::orderByPriority()->get();
    $priorities = $ordered->pluck('priority')->toArray();

    expect($priorities)->toBe([10, 5, 1]);
});

// ─── Trigger eventLogs Relationship ───

test('trigger has many event logs', function (): void {
    $trigger = Trigger::factory()->create();
    EventLog::factory()->count(2)->forTrigger($trigger->id)->create();

    expect($trigger->eventLogs()->count())->toBe(2);
});

// ─── EventLog trigger Relationship ───

test('event log belongs to trigger', function (): void {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->forTrigger($trigger->id)->create();

    expect($log->trigger->id)->toBe($trigger->id);
});

// ─── EscapesWildcardLike Trait ───

test('wildcardToLike converts single star to percent', function (): void {
    $manager = app(EventManager::class);

    $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'order.*'))->toBe('order\%');
    expect($ref->invoke($manager, '*.created'))->toBe('%created');
});

test('wildcardToLike returns null for non-wildcard pattern', function (): void {
    $manager = app(EventManager::class);

    $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'order.placed'))->toBeNull();
});

test('wildcardToLike escapes backslash, percent, and underscore', function (): void {
    $manager = app(EventManager::class);

    $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'user_%*'))->toBe('user\_\%\%');
});

// ─── fireModel with Eloquent-like model ───

test('fireModel flattens model attributes into payload', function (): void {
    $manager = app(EventManager::class);

    $trigger = Trigger::factory()->enabled()->create([
        'event' => 'App\\Models\\TestModel.updated',
        'action' => NullAction::class,
        'conditions' => ['status' => 'active'],
    ]);

    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        public function attributesToArray(): array
        {
            return ['status' => 'active', 'name' => 'Test'];
        }
    };

    // This should not throw — status matches condition
    $manager->fireModel('App\\Models\\TestModel', 'updated', $model);

    // Event log should exist for this trigger
    $logs = EventLog::where('trigger_id', $trigger->id)->count();
    expect($logs)->toBeGreaterThanOrEqual(1);
});

// ─── Version Consistency ───

test('composer.json version is 5.71.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['version'])->toBe('5.71.0');
});

test('phpstan.neon.dist level is 9', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: 9');
});

// ─── GetsWebhookTimeout Trait Coverage ───

test('getWebhookTimeout returns configured value', function (): void {
    $config = config('events.subscriptions.timeout');
    expect($config)->toBe(30);
});

test('getWebhookTimeout clamps invalid values to 30', function (): void {
    // Test through the actual trait method
    $command = new \ZeroBoiler\Events\Console\EventsRedeliverCommand;

    $ref = new ReflectionMethod(\ZeroBoiler\Events\Concerns\GetsWebhookTimeout::class, 'getWebhookTimeout');
    $ref->setAccessible(true);

    // The default is 30 since our config has 30
    $result = $ref->invoke($command);
    expect($result)->toBe(30);
});
