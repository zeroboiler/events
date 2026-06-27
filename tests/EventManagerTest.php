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
