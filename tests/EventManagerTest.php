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
use App\Actions\TrackingActionOne;
use App\Actions\TrackingActionTwo;
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

test('getMatchingTriggers does not load all enabled triggers for non-wildcard events', function (): void {
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

    // An enabled trigger that should NOT be loaded (no wildcard, different event)
    Trigger::factory()->create([
        'event' => 'user.created',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    DB::enableQueryLog();

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    $queries = DB::getQueryLog();
    $selectQueries = array_filter($queries, fn (array $q): bool => str_contains((string) $q['query'], 'select'));

    // Verify no query loads ALL enabled triggers without a wildcard or exact filter
    foreach ($selectQueries as $query) {
        $sql = $query['query'];

        // Every trigger query must have either an exact event lookup or a wildcard LIKE filter

        // A query that selects from triggers table must include event filtering
        if (str_contains((string) $sql, '"triggers"')) {
            expect($sql)
                ->toContain('"event"')
                ->and($sql)
                ->not->toBe('select * from "triggers"');
        }
    }

    DB::disableQueryLog();
});

test('getMatchingTriggers uses LIKE wildcard filter for pattern triggers', function (): void {
    Trigger::factory()->create([
        'event' => 'order.*',
        'action' => LogOrderEvent::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    DB::enableQueryLog();

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    $queries = DB::getQueryLog();

    // The wildcard query should have a LIKE clause with '%*%' as a binding
    $wildcardQuery = collect($queries)->first(function (array $q): bool {
        $hasLike = str_contains(strtolower((string) $q['query']), 'like');
        $hasWildcardBinding = in_array('%*%', $q['bindings'], true);

        return $hasLike && $hasWildcardBinding;
    });

    expect($wildcardQuery)->not->toBeNull('Expected a query with LIKE %*% filter for wildcard triggers');

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

// ---------------------------------------------------------------------------
// Regression tests for issue #6: TriggerBuilder loses multiple actions when
// action params are used (the "classes" JSON key was not parsed).
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    TrackingActionOne::reset();
    TrackingActionTwo::reset();
});

test('fire trigger with multiple actions and params executes all actions', function (): void {
    // Build a trigger using actions() + actionParams() — this creates the
    // {"classes":[...],"params":{...}} format that was silently broken (#6).
    $builder = app(TriggerBuilder::class);
    $builder
        ->on('order.placed')
        ->actions([TrackingActionOne::class, TrackingActionTwo::class])
        ->actionParams(['webhook_url' => 'https://example.com/hook'])
        ->save();

    EventManagerFacade::fire('order.placed', ['order_id' => 42]);

    expect(TrackingActionOne::$fired)->toBeTrue('TrackingActionOne should have been dispatched')
        ->and(TrackingActionTwo::$fired)->toBeTrue('TrackingActionTwo should have been dispatched')
        ->and(TrackingActionOne::$lastPayload)->toHaveKey('webhook_url')
        ->and(TrackingActionOne::$lastPayload['webhook_url'])->toBe('https://example.com/hook')
        ->and(TrackingActionOne::$lastPayload)->toHaveKey('order_id')
        ->and(TrackingActionTwo::$lastPayload)->toHaveKey('webhook_url');
});

test('fire trigger with single action and params works correctly', function (): void {
    $builder = app(TriggerBuilder::class);
    $builder
        ->on('order.shipped')
        ->action(TrackingActionOne::class)
        ->actionParams(['channel' => 'email'])
        ->save();

    EventManagerFacade::fire('order.shipped', ['order_id' => 99]);

    expect(TrackingActionOne::$fired)->toBeTrue()
        ->and(TrackingActionOne::$lastPayload)->toHaveKey('channel')
        ->and(TrackingActionOne::$lastPayload['channel'])->toBe('email')
        ->and(TrackingActionOne::$lastPayload)->toHaveKey('order_id');
});

test('fire trigger with multiple actions without params executes all actions', function (): void {
    $builder = app(TriggerBuilder::class);
    $builder
        ->on('user.created')
        ->actions([TrackingActionOne::class, TrackingActionTwo::class])
        ->save();

    EventManagerFacade::fire('user.created', ['user_id' => 7]);

    expect(TrackingActionOne::$fired)->toBeTrue()
        ->and(TrackingActionTwo::$fired)->toBeTrue();
});

test('action and actions merge includes both when called together', function (): void {
    // When both action() and actions() are called, resolveActions() merges them.
    $builder = app(TriggerBuilder::class);
    $builder
        ->on('user.updated')
        ->action(TrackingActionOne::class)
        ->actions([TrackingActionTwo::class])
        ->save();

    EventManagerFacade::fire('user.updated', ['user_id' => 3]);

    expect(TrackingActionOne::$fired)->toBeTrue('Merged action() class should fire')
        ->and(TrackingActionTwo::$fired)->toBeTrue('actions() class should fire');
});

test('trigger builder saves classes key format for multiple actions with params', function (): void {
    $builder = app(TriggerBuilder::class);
    $trigger = $builder
        ->on('order.cancelled')
        ->actions([TrackingActionOne::class, TrackingActionTwo::class])
        ->actionParams(['url' => 'https://hook.example.com'])
        ->save();

    $decoded = json_decode($trigger->action, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKey('classes')
        ->and($decoded['classes'])->toBe([TrackingActionOne::class, TrackingActionTwo::class])
        ->and($decoded)->toHaveKey('params')
        ->and($decoded['params'])->toBe(['url' => 'https://hook.example.com']);
});
