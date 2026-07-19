<?php

/**
 * Regression tests for issue #3: EventManager::fire() has no error boundary
 * — partial dispatch on failure.
 *
 * When one trigger throws, remaining triggers should still fire.
 * The first exception should be re-thrown after all triggers attempted.
 *
 * @see https://github.com/zeroboiler/events/issues/3
 */

declare(strict_types=1);

use App\Actions\LogOrderEvent;
use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes
require_once __DIR__.'/TestActions.php';

/**
 * A triggerable action that always throws.
 */
class FailingAction implements Triggerable
{
    public function handle(array $payload): void
    {
        throw new \RuntimeException('Action failed intentionally');
    }
}

/**
 * An action that records that it was called.
 */
class RecordingAction implements Triggerable
{
    public static bool $called = false;

    public function handle(array $payload): void
    {
        self::$called = true;
    }
}

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
    RecordingAction::$called = false;
});

it('continues dispatching remaining triggers when one fails', function (): void {
    // First trigger fails
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => FailingAction::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
        'priority' => 100, // fires first
    ]);

    // Second trigger should still fire
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => RecordingAction::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
        'priority' => 50, // fires second
    ]);

    try {
        EventManagerFacade::fire('order.placed', ['order_id' => 123]);
    } catch (\RuntimeException $e) {
        // Expected — the first exception is re-thrown
    }

    // The second trigger should have fired despite the first one failing
    expect(RecordingAction::$called)->toBeTrue('Second trigger should fire even when first trigger fails');
});

it('logs failed trigger but still creates event logs for successful ones', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => FailingAction::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
        'priority' => 100,
    ]);

    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
        'priority' => 50,
    ]);

    try {
        EventManagerFacade::fire('order.placed', ['order_id' => 123]);
    } catch (\RuntimeException) {
        // Expected
    }

    $logs = EventLog::all();

    // Both triggers should have created event logs
    expect($logs)->toHaveCount(2);

    $failedLog = $logs->firstWhere('status', EventLog::STATUS_FAILED);
    $completedLog = $logs->firstWhere('status', EventLog::STATUS_COMPLETED);

    expect($failedLog)->not->toBeNull('Failed trigger should have a failed event log')
        ->and($completedLog)->not->toBeNull('Successful trigger should have a completed event log');
});

it('re-throws the first exception after all triggers attempted', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => FailingAction::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);
})->throws(\RuntimeException::class, 'Action failed intentionally');

it('does not throw when all triggers succeed', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => LogOrderEvent::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Should not throw
    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    expect(EventLog::count())->toBe(2);
});
