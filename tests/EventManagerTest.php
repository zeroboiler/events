<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\HighPriority;
use App\Actions\LogOrderCreated;
use App\Actions\LogOrderEvent;
use App\Actions\LowPriority;
use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;

// Load test action classes (App\Actions namespace)
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

test('event manager facade returns correct instance', function (): void {
    expect(EventManagerFacade::getFacadeRoot())
        ->toBeInstanceOf(EventManager::class);
});

test('can create trigger using builder', function (): void {
    $builder = app(TriggerBuilder::class);
    $trigger = $builder
        ->name('Test Trigger')
        ->on('order.placed')
        ->action(SendOrderNotification::class)
        ->save();

    expect($trigger->name)->toBe('Test Trigger')
        ->and($trigger->event)->toBe('order.placed')
        ->and($trigger->action)->toBe(SendOrderNotification::class)
        ->and($trigger->enabled)->toBeTrue();
});

test('fire event with exact match', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    expect(EventLog::count())->toBe(1)
        ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
});

test('fire event with wildcard match', function (): void {
    Trigger::factory()->create([
        'event' => 'order.*',
        'action' => LogOrderEvent::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);
    EventManagerFacade::fire('order.shipped', ['order_id' => 123]);

    expect(EventLog::count())->toBe(2);
});

test('fire event does not dispatch disabled triggers', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => false,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    expect(EventLog::count())->toBe(0);
});

test('fire event respects conditions', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => ['amount' => ['>', 100]],
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['amount' => 150]);
    EventManagerFacade::fire('order.placed', ['amount' => 50]);

    expect(EventLog::count())->toBe(1);
});

test('fire event queues async triggers', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => true,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    Queue::assertPushed(DispatchTriggerJob::class);
});

test('fire event handles sync triggers immediately', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    expect(EventLog::first()->status)
        ->not->toBe(EventLog::STATUS_PENDING);
});

test('fire event respects priority', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => LowPriority::class,
        'conditions' => null,
        'priority' => 10,
        'enabled' => true,
        'async' => false,
    ]);

    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => HighPriority::class,
        'conditions' => null,
        'priority' => 100,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', []);

    $logs = EventLog::with('trigger')->orderBy('created_at')->get();
    expect($logs[0]->trigger->action)->toBe(HighPriority::class)
        ->and($logs[1]->trigger->action)->toBe(LowPriority::class);
});

test('fire model event generates correct event name', function (): void {
    Trigger::factory()->create([
        'event' => 'App\\Models\\Order.created',
        'action' => LogOrderCreated::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    $order = new class
    {
        public $id = 123;
    };

    EventManagerFacade::fireModel('App\\Models\\Order', 'created', $order);

    expect(EventLog::count())->toBe(1)
        ->and(EventLog::first()->event)->toBe('App\\Models\\Order.created');
});

test('enable trigger', function (): void {
    $trigger = Trigger::factory()->create(['enabled' => false]);

    expect(EventManagerFacade::enable($trigger->id))->toBeTrue();

    $trigger->refresh();
    expect($trigger->enabled)->toBeTrue();
});

test('disable trigger', function (): void {
    $trigger = Trigger::factory()->create(['enabled' => true]);

    expect(EventManagerFacade::disable($trigger->id))->toBeTrue();

    $trigger->refresh();
    expect($trigger->enabled)->toBeFalse();
});

test('builder requires event name', function (): void {
    $builder = app(TriggerBuilder::class);

    $this->expectException(InvalidArgumentException::class);
    $builder->save();
});

test('builder requires action', function (): void {
    $builder = app(TriggerBuilder::class);
    $builder->on('order.placed');

    $this->expectException(InvalidArgumentException::class);
    $builder->save();
});

test('builder generates name from event if not provided', function (): void {
    $builder = app(TriggerBuilder::class);
    $trigger = $builder
        ->on('order.placed')
        ->action(SendOrderNotification::class)
        ->save();

    expect($trigger->name)->toBe('order.placed Trigger');
});

test('on method is alias for register', function (): void {
    $builder1 = app(EventManager::class)->on('test.event');
    $builder2 = app(EventManager::class)->register('test.event');

    expect($builder1)->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder2)->toBeInstanceOf(TriggerBuilder::class);
});

test('getMatchingTriggers fires zero DB queries on cache hit', function (): void {
    // Create a mix of triggers: exact, wildcard, and unrelated
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    Trigger::factory()->create([
        'event' => 'order.*',
        'action' => LogOrderEvent::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // First fire() populates the cache (single DB query for all enabled triggers)
    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    // Second fire() should serve entirely from cache — zero DB queries
    DB::enableQueryLog();

    EventManagerFacade::fire('order.shipped', ['order_id' => 456]);

    $queries = DB::getQueryLog();
    $selectQueries = array_filter($queries, fn (array $q): bool => str_contains((string) $q['query'], 'select'));

    // On cache hit, no trigger queries should hit the DB
    $triggerSelectQueries = array_filter($selectQueries, fn (array $q): bool => str_contains((string) $q['query'], '"triggers"'));
    expect($triggerSelectQueries)->toBeEmpty();

    DB::disableQueryLog();
});

test('getMatchingTriggers uses single cached query for all enabled triggers', function (): void {
    Trigger::factory()->create([
        'event' => 'order.*',
        'action' => LogOrderEvent::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    Trigger::factory()->create([
        'event' => 'user.created',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // First fire — cache miss, should execute exactly ONE trigger query
    DB::enableQueryLog();

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    $queries = DB::getQueryLog();
    $triggerQueries = array_filter($queries, fn (array $q): bool => str_contains((string) $q['query'], '"triggers"'));

    // Single query to load all enabled triggers (no LIKE filter, no event filter)
    expect($triggerQueries)->toHaveCount(1);

    DB::disableQueryLog();
});

test('getMatchingTriggers deduplicates exact and wildcard matches', function (): void {
    // Trigger matching both exact and wildcard patterns
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
        'priority' => 50,
    ]);

    // Wildcard that also matches 'order.placed'
    Trigger::factory()->create([
        'event' => 'order.*',
        'action' => LogOrderEvent::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
        'priority' => 50,
    ]);

    DB::enableQueryLog();

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    // Should produce exactly 2 event logs (one per unique trigger), not duplicates
    expect(EventLog::count())->toBe(2);

    DB::disableQueryLog();
});

test('cache is invalidated when a trigger is enabled', function (): void {
    // Start with a disabled trigger
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => false,
        'async' => false,
    ]);

    // Prime the cache — trigger is disabled, won't be in results
    EventManagerFacade::fire('order.placed', ['order_id' => 123]);
    expect(EventLog::count())->toBe(0);

    // Enable the trigger — should invalidate cache
    EventManagerFacade::enable($trigger->id);

    // Now fire() should see the newly-enabled trigger
    EventManagerFacade::fire('order.placed', ['order_id' => 456]);
    expect(EventLog::count())->toBe(1);
});

test('cache is invalidated when a trigger is disabled', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Prime the cache — trigger fires
    EventManagerFacade::fire('order.placed', ['order_id' => 123]);
    expect(EventLog::count())->toBe(1);

    // Disable the trigger — should invalidate cache
    EventManagerFacade::disable($trigger->id);

    // Now fire() should NOT trigger the disabled trigger
    EventManagerFacade::fire('order.placed', ['order_id' => 456]);
    expect(EventLog::count())->toBe(1); // Still 1 — no new log from second fire
});

test('getMatchingTriggers does not match unrelated non-wildcard events', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Unrelated exact event
    Trigger::factory()->create([
        'event' => 'user.created',
        'action' => LogOrderEvent::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    // Only the matching trigger should fire
    expect(EventLog::count())->toBe(1);
    expect(EventLog::first()->trigger_id)->not->toBeNull();
});
