<?php

/**
 * Regression tests for issue #7: DispatchTriggerJob has race condition
 * — EventLog may be processed concurrently.
 *
 * The fix uses atomic status transition (pending → dispatched) via a
 * conditional UPDATE query. If another worker already transitioned the
 * log, execution is skipped.
 *
 * @see https://github.com/zeroboiler/events/issues/7
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

it('executeTrigger atomically transitions status from pending to dispatched', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    $log = EventLog::first();

    // After execution, status should be completed (not stuck in dispatched)
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
});

it('skips execution when EventLog is no longer pending', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Create an EventLog that is already dispatched (simulating another worker)
    $log = EventLog::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'trigger_id' => $trigger->id,
        'event' => 'order.placed',
        'payload' => ['order_id' => 123],
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $eventManager = app(EventManager::class);

    // Calling executeTrigger should skip execution because status is not pending
    $eventManager->executeTrigger($trigger, $log);

    // The log should still be STATUS_DISPATCHED (not completed by this call)
    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_DISPATCHED);
});

it('does not create duplicate event logs when job is retried', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // First execution — should process normally
    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    expect(EventLog::count())->toBe(1);

    $log = EventLog::first();

    // Simulate a retry: call executeTrigger again with the same log
    // The log is already completed, so the atomic update should fail (status != pending)
    $eventManager = app(EventManager::class);
    $eventManager->executeTrigger($trigger, $log);

    // Should still have only 1 event log
    expect(EventLog::count())->toBe(1);
});
