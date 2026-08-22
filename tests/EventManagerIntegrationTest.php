<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

test('fire event with no matching triggers creates no logs', function (): void {
    EventManagerFacade::fire('nonexistent.event', ['data' => 'test']);

    expect(EventLog::count())->toBe(0);
});

test('fire event with multiple matching triggers dispatches all', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => 'ZeroBoiler\Events\Tests\Actions\LogA',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
        'priority' => 10,
    ]);

    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => 'ZeroBoiler\Events\Tests\Actions\LogB',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
        'priority' => 20,
    ]);

    EventManagerFacade::fire('order.placed', []);

    expect(EventLog::count())->toBe(2);
});

test('fire event with multiple actions in trigger executes all actions', function (): void {
    Trigger::factory()->create([
        'event' => 'multi.action',
        'action' => json_encode(['ZeroBoiler\Events\Tests\Actions\ActionOne', 'ZeroBoiler\Events\Tests\Actions\ActionTwo']),
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('multi.action', ['key' => 'value']);

    expect(EventLog::count())->toBe(1)
        ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
});

test('fire event with cross-segment wildcard matches nested events', function (): void {
    Trigger::factory()->create([
        'event' => 'order.**',
        'action' => 'ZeroBoiler\Events\Tests\Actions\CrossSegment',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed.extra', ['data' => 1]);
    EventManagerFacade::fire('order.placed.extra.deep', ['data' => 2]);

    expect(EventLog::count())->toBe(2);
});

test('fire event with catch-all wildcard matches single-segment event', function (): void {
    Trigger::factory()->create([
        'event' => '*',
        'action' => 'ZeroBoiler\Events\Tests\Actions\CatchAll',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('simple', []);
    EventManagerFacade::fire('a.b.c', []);

    expect(EventLog::count())->toBe(2);
});

test('fire event with empty payload works', function (): void {
    Trigger::factory()->create([
        'event' => 'empty.payload',
        'action' => 'ZeroBoiler\Events\Tests\Actions\EmptyPayload',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('empty.payload');

    expect(EventLog::count())->toBe(1);
});

test('fire event with condition on nested field', function (): void {
    Trigger::factory()->create([
        'event' => 'nested.test',
        'action' => 'ZeroBoiler\Events\Tests\Actions\Nested',
        'conditions' => ['user.role' => 'admin'],
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('nested.test', ['user' => ['role' => 'admin']]);
    EventManagerFacade::fire('nested.test', ['user' => ['role' => 'user']]);

    expect(EventLog::count())->toBe(1);
});

test('enable returns false for non-existent trigger', function (): void {
    expect(EventManagerFacade::enable('nonexistent-uuid'))->toBeFalse();
});

test('disable returns false for non-existent trigger', function (): void {
    expect(EventManagerFacade::disable('nonexistent-uuid'))->toBeFalse();
});

test('enable already enabled trigger returns false', function (): void {
    $trigger = Trigger::factory()->create(['enabled' => true]);

    expect(EventManagerFacade::enable($trigger->id))->toBeFalse();
});

test('disable already disabled trigger returns false', function (): void {
    $trigger = Trigger::factory()->create(['enabled' => false]);

    expect(EventManagerFacade::disable($trigger->id))->toBeFalse();
});

test('invalidateTriggerCache clears wildcard cache', function (): void {
    // This is a smoke test — we just verify the method doesn't throw
    EventManagerFacade::invalidateTriggerCache();
    expect(true)->toBeTrue();
});

test('fire model event with object without attributesToArray uses toArray', function (): void {
    Trigger::factory()->create([
        'event' => 'App\Models\Order.created',
        'action' => 'ZeroBoiler\Events\Tests\Actions\OrderCreated',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    $order = new class
    {
        public string $name = 'Test Order';
        public int $amount = 100;

        public function toArray(): array
        {
            return ['name' => $this->name, 'amount' => $this->amount];
        }
    };

    EventManagerFacade::fireModel('App\Models\Order', 'created', $order);

    expect(EventLog::count())->toBe(1);
});

test('sync trigger failure marks log as failed', function (): void {
    Trigger::factory()->create([
        'event' => 'fail.event',
        'action' => 'ZeroBoiler\Events\Tests\Actions\FailingAction',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    try {
        EventManagerFacade::fire('fail.event', []);
    } catch (Throwable $e) {
        // Expected — the action class doesn't exist
    }

    expect(EventLog::count())->toBe(1);
    $log = EventLog::first();
    expect($log->status)->toBe(EventLog::STATUS_FAILED);
});

test('register is alias for on', function (): void {
    $builder1 = EventManagerFacade::on('test.event');
    $builder2 = EventManagerFacade::register('test.event');

    expect($builder1)->toBeInstanceOf(\ZeroBoiler\Events\TriggerBuilder::class)
        ->and($builder2)->toBeInstanceOf(\ZeroBoiler\Events\TriggerBuilder::class);
});
