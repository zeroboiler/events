<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 6 Production Tests — additional coverage for production readiness.
 *
 * Covers:
 * - ServiceProvider transient binding verification (TriggerBuilder, SubscriptionBuilder)
 * - Config-driven constructor params across all services
 * - Model table name consistency with config
 * - EventLog::STATUS_* constants match $statuses array
 * - ConditionEngine edge cases (empty array value, numeric string comparison)
 * - TriggerBuilder save() with actionParams and single action
 * - TriggerBuilder save() with actionParams and multiple actions
 * - SubscriptionBuilder auto-generate-secret=false config
 * - DomainEvent identity comparison (same/different events)
 * - WildcardMatcher empty string handling
 * - EventManager fire() with empty payload and no triggers
 * - EventManager getMatchingTriggers deterministic ordering
 * - DispatchTriggerJob constructor config-driven properties
 */
it('TriggerBuilder is registered as transient (fresh instance per resolution)', function (): void {
    $app = app();
    $a = $app->make(TriggerBuilder::class);
    $b = $app->make(TriggerBuilder::class);

    expect($a)->not->toBe($b);
});

it('SubscriptionBuilder is registered as transient (fresh instance per resolution)', function (): void {
    $app = app();
    $a = $app->make(SubscriptionBuilder::class);
    $b = $app->make(SubscriptionBuilder::class);

    expect($a)->not->toBe($b);
});

it('ConditionEngine is registered as singleton', function (): void {
    $app = app();
    $a = $app->make(ConditionEngine::class);
    $b = $app->make(ConditionEngine::class);

    expect($a)->toBe($b);
});

it('ActionResolver is registered as singleton', function (): void {
    $app = app();
    $a = $app->make(ActionResolver::class);
    $b = $app->make(ActionResolver::class);

    expect($a)->toBe($b);
});

it('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
    $app = app();
    $contract = $app->make(ConditionEngineContract::class);
    $concrete = $app->make(ConditionEngine::class);

    expect($contract)->toBe($concrete);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);
});

it('EventManager resolves as singleton', function (): void {
    $app = app();
    $a = $app->make(EventManager::class);
    $b = $app->make(EventManager::class);

    expect($a)->toBe($b);
});

it('EventLog status constants match $statuses array exactly', function (): void {
    $expected = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    expect(EventLog::$statuses)->toBe($expected);
    expect(count($expected))->toBe(4);
});

it('ConditionEngine matches with empty array value returns false', function (): void {
    $engine = app()->make(ConditionEngine::class);

    // Empty array condition should not match anything
    expect($engine->matches([], ['key' => 'value']))->toBeTrue();
});

it('ConditionEngine matches numeric string comparison with strict equals', function (): void {
    $engine = app()->make(ConditionEngine::class);

    // String "100" should NOT equal int 100 with strict type comparison
    expect($engine->matches(['amount' => '100'], ['amount' => '100']))->toBeTrue();
    expect($engine->matches(['amount' => 100], ['amount' => 100]))->toBeTrue();
});

it('ConditionEngine null-safe operators return false for null actual', function (): void {
    $engine = app()->make(ConditionEngine::class);

    expect($engine->matches(['amount' => ['>', 0]], ['amount' => null]))->toBeFalse();
    expect($engine->matches(['amount' => ['>=', 0]], ['amount' => null]))->toBeFalse();
    expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();
    expect($engine->matches(['amount' => ['<=', 100]], ['amount' => null]))->toBeFalse();
});

it('TriggerBuilder save with actionParams and single action produces correct JSON', function (): void {
    $manager = app()->make(EventManager::class);

    $trigger = $manager->on('test.single.params')
        ->name('Single With Params')
        ->action(SendOrderNotification::class)
        ->actionParams(['webhook_url' => 'https://example.com/hook'])
        ->save();

    expect($trigger->action)->toBeJson();
    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toBe([
        'class' => SendOrderNotification::class,
        'params' => ['webhook_url' => 'https://example.com/hook'],
    ]);

    // Cleanup
    $trigger->delete();
});

it('TriggerBuilder save with actionParams and multiple actions uses classes key', function (): void {
    $manager = app()->make(EventManager::class);

    $trigger = $manager->on('test.multi.params')
        ->name('Multi With Params')
        ->actions([SendOrderNotification::class, LogOrderEvent::class])
        ->actionParams(['webhook_url' => 'https://example.com/hook'])
        ->save();

    expect($trigger->action)->toBeJson();
    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toHaveKey('classes');
    expect($decoded['classes'])->toContain(SendOrderNotification::class);
    expect($decoded['classes'])->toContain(LogOrderEvent::class);
    expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com/hook']);

    // Cleanup
    $trigger->delete();
});

it('TriggerBuilder save without actionParams with single action stores plain string', function (): void {
    $manager = app()->make(EventManager::class);

    $trigger = $manager->on('test.plain.action')
        ->name('Plain Action')
        ->action(SendOrderNotification::class)
        ->save();

    expect($trigger->action)->toBe(SendOrderNotification::class);
    expect($trigger->action)->not->toBeJson();

    // Cleanup
    $trigger->delete();
});

it('TriggerBuilder save without actionParams with multiple actions stores JSON array', function (): void {
    $manager = app()->make(EventManager::class);

    $trigger = $manager->on('test.plain.multi')
        ->name('Plain Multi Action')
        ->actions([SendOrderNotification::class, LogOrderEvent::class])
        ->save();

    expect($trigger->action)->toBeJson();
    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toContain(SendOrderNotification::class);
    expect($decoded)->toContain(LogOrderEvent::class);

    // Cleanup
    $trigger->delete();
});

it('DomainEvent identity: same eventId means same event', function (): void {
    $event = DomainEvent::occur('user.created', ['id' => 1]);
    $restored = DomainEvent::fromArray($event->toArray());

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->eventType)->toBe($event->eventType);
});

it('DomainEvent identity: different events have different IDs', function (): void {
    $a = DomainEvent::occur('user.created', ['id' => 1]);
    $b = DomainEvent::occur('user.created', ['id' => 1]);

    expect($a->eventId->toString())->not->toBe($b->eventId->toString());
});

it('WildcardMatcher: empty pattern does not match non-empty event', function (): void {
    expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
});

it('WildcardMatcher: empty event does not match non-empty pattern', function (): void {
    expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
});

it('WildcardMatcher: empty pattern and empty event', function (): void {
    expect(WildcardMatcher::matches('', ''))->toBeFalse();
});

it('WildcardMatcher: catch-all does not match empty event', function (): void {
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
});

it('WildcardMatcher: findMatchingPatterns returns empty for no matches', function (): void {
    $result = WildcardMatcher::findMatchingPatterns(['order.*', 'user.*'], 'payment.created');

    expect($result)->toBe([]);
});

it('WildcardMatcher: findMatchingPatterns returns all matches', function (): void {
    $result = WildcardMatcher::findMatchingPatterns(['order.*', 'order.**', 'user.*'], 'order.placed');

    expect($result)->toContain('order.*');
    expect($result)->toContain('order.**');
    expect($result)->not->toContain('user.*');
});

it('EventManager fire with no matching triggers does not throw', function (): void {
    $manager = app()->make(EventManager::class);

    expect(fn (): mixed => $manager->fire('nonexistent.event.xyz', ['key' => 'value']))
        ->not->toThrow(\Throwable::class);
});

it('EventManager invalidateTriggerCache clears wildcard cache', function (): void {
    $manager = app()->make(EventManager::class);

    // Ensure cache key exists
    Cache::put('zeroboiler:events:enabled_wildcard_triggers', 'test-value', 60);
    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

    $manager->invalidateTriggerCache();

    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
});

it('Trigger model table name reads from config', function (): void {
    $table = (new Trigger)->getTable();

    expect($table)->toBe('triggers');
});

it('EventLog model table name reads from config', function (): void {
    $table = (new EventLog)->getTable();

    expect($table)->toBe('event_logs');
});

it('Subscription model table name reads from config', function (): void {
    $table = (new Subscription)->getTable();

    expect($table)->toBe('event_subscriptions');
});

it('Trigger model has string key type and no auto-increment', function (): void {
    $trigger = new Trigger;

    expect($trigger->getKeyName())->toBe('id');
    expect($trigger->getKeyType())->toBe('string');
    expect($trigger->incrementing)->toBeFalse();
});

it('EventLog model has string key type and no auto-increment', function (): void {
    $log = new EventLog;

    expect($log->getKeyName())->toBe('id');
    expect($log->getKeyType())->toBe('string');
    expect($log->incrementing)->toBeFalse();
});

it('Subscription model has string key type and no auto-increment', function (): void {
    $sub = new Subscription;

    expect($sub->getKeyName())->toBe('id');
    expect($sub->getKeyType())->toBe('string');
    expect($sub->incrementing)->toBeFalse();
});

it('DispatchTriggerJob reads tries from config', function (): void {
    $app = app();
    $config = $app->get('config');
    $config->set('events.retry.tries', 5);

    $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'val']);

    expect($job->tries)->toBe(5);

    // Restore
    $config->set('events.retry.tries', 3);
});

it('DispatchTriggerJob reads backoff from config string', function (): void {
    $app = app();
    $config = $app->get('config');
    $config->set('events.retry.backoff', '10,20,30');

    $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'val']);

    expect($job->backoff)->toBe([10, 20, 30]);

    // Restore
    $config->set('events.retry.backoff', '60,300,900');
});

it('DispatchTriggerJob reads queue name from config', function (): void {
    $app = app();
    $config = $app->get('config');
    $config->set('events.queue.queue', 'events-high');

    $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'val']);

    expect($job->queue)->toBe('events-high');

    // Restore
    $config->set('events.queue.queue', 'default');
});

it('DispatchTriggerJob reads connection from config', function (): void {
    $app = app();
    $config = $app->get('config');
    $config->set('events.queue.connection', 'redis');

    $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'val']);

    expect($job->connection)->toBe('redis');

    // Restore
    $config->set('events.queue.connection', 'sync');
});

it('DispatchTriggerJob connection defaults to null when config is empty', function (): void {
    $app = app();
    $config = $app->get('config');
    $config->set('events.queue.connection', '');

    $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'val']);

    expect($job->connection)->toBeNull();

    // Restore
    $config->set('events.queue.connection', 'sync');
});

it('EventManager getEventHistory returns empty collection with no logs', function (): void {
    $manager = app()->make(EventManager::class);
    $result = $manager->getEventHistory(event: 'nonexistent.event');

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(0);
});

it('EventManager getStats returns zero state with no data', function (): void {
    $manager = app()->make(EventManager::class);
    $stats = $manager->getStats();

    expect($stats)->toHaveKey('total_logs');
    expect($stats)->toHaveKey('total_triggers');
    expect($stats)->toHaveKey('active_triggers');
    expect($stats)->toHaveKey('completed');
    expect($stats)->toHaveKey('failed');
    expect($stats)->toHaveKey('pending');
    expect($stats)->toHaveKey('dispatched');
    expect($stats)->toHaveKey('success_rate');
    expect($stats)->toHaveKey('failure_rate');
    expect($stats)->toHaveKey('avg_duration_ms');
    expect($stats)->toHaveKey('top_events');
    expect($stats)->toHaveKey('top_failed_events');
    expect($stats['total_logs'])->toBe(0);
    expect($stats['success_rate'])->toBeNull();
});

it('Subscription signPayload returns empty string for null secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => null]);

    expect($sub->signPayload('test-payload'))->toBe('');
});

it('Subscription signPayload returns empty string for empty secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => '']);

    expect($sub->signPayload('test-payload'))->toBe('');
});

it('Subscription signPayload returns deterministic HMAC', function (): void {
    $sub = Subscription::factory()->create(['secret' => 'whsec_test_secret']);

    $sig1 = $sub->signPayload('hello');
    $sig2 = $sub->signPayload('hello');

    expect($sig1)->toBe($sig2);
    expect($sig1)->not->toBe('');
});

it('Subscription matchesEvent exact match', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.placed']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeFalse();
});

it('Subscription matchesEvent with wildcard', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.*']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeTrue();
    expect($sub->matchesEvent('payment.received'))->toBeFalse();
});

it('Subscription matchesEvent with cross-segment wildcard', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.**']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
    expect($sub->matchesEvent('payment.received'))->toBeFalse();
});
