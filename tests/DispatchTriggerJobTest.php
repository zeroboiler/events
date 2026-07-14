<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestCase;

// Load test action classes
require_once __DIR__.'/TestActions.php';

uses(TestCase::class);

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

test('executeTrigger uses atomic status transition', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    $log = EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.placed',
        'status' => EventLog::STATUS_PENDING,
    ]);

    /** @var EventManager $eventManager */
    $eventManager = app(EventManager::class);

    // First execution should succeed (PENDING → DISPATCHED atomically)
    $eventManager->executeTrigger($trigger, $log->fresh());

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);

    // Create another pending log for the same trigger
    $log2 = EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.placed',
        'status' => EventLog::STATUS_PENDING,
    ]);

    // Manually set to DISPATCHED to simulate race condition
    EventLog::where('id', $log2->id)->update(['status' => EventLog::STATUS_DISPATCHED]);

    // executeTrigger should detect it's already dispatched and skip
    $eventManager->executeTrigger($trigger, $log2->fresh());

    $log2->refresh();
    // Status should still be DISPATCHED (not completed, because execution was skipped)
    expect($log2->status)->toBe(EventLog::STATUS_DISPATCHED);
});

test('DispatchTriggerJob skips duplicate when trigger already dispatched', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Create an existing EventLog in DISPATCHED state (simulating prior processing)
    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.placed',
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $job = new DispatchTriggerJob($trigger->id, 'order.placed', ['order_id' => 123]);
    $job->handle();

    // No new EventLog should have been created
    expect(EventLog::where('trigger_id', $trigger->id)->count())->toBe(1);
});

test('DispatchTriggerJob skips duplicate when trigger already completed', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.shipped',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Create an existing EventLog in COMPLETED state
    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.shipped',
        'status' => EventLog::STATUS_COMPLETED,
    ]);

    $job = new DispatchTriggerJob($trigger->id, 'order.shipped', ['order_id' => 456]);
    $job->handle();

    expect(EventLog::where('trigger_id', $trigger->id)->count())->toBe(1);
});

test('DispatchTriggerJob processes when no prior log exists', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.cancelled',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    $job = new DispatchTriggerJob($trigger->id, 'order.cancelled', ['order_id' => 789]);
    $job->handle();

    // Should create one new EventLog and execute the trigger
    expect(EventLog::where('trigger_id', $trigger->id)->count())->toBe(1)
        ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
});

test('DispatchTriggerJob allows retry when prior log failed', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.refunded',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // A FAILED log should not block retry
    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.refunded',
        'status' => EventLog::STATUS_FAILED,
    ]);

    $job = new DispatchTriggerJob($trigger->id, 'order.refunded', ['order_id' => 111]);
    $job->handle();

    // Should create a new EventLog for the retry
    expect(EventLog::where('trigger_id', $trigger->id)->count())->toBe(2);
});
