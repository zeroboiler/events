<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    // Ensure sync queue for deterministic testing
    config()->set('queue.default', 'sync');
});

test('dispatch trigger job creates event log and executes trigger', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'test.job.execute',
        'action' => TestJobAction::class,
        'enabled' => true,
        'async' => true,
    ]);

    $job = new DispatchTriggerJob(
        $trigger->id,
        'test.job.execute',
        ['key' => 'value'],
    );

    $job->handle();

    $logs = EventLog::where('trigger_id', $trigger->id)->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($logs->first()->event)->toBe('test.job.execute')
        ->and($logs->first()->payload)->toBe(['key' => 'value']);
});

test('dispatch trigger job skips when trigger not found', function (): void {
    $job = new DispatchTriggerJob(
        'nonexistent-id',
        'test.event',
        [],
    );

    // Should not throw — just logs a warning and returns
    $job->handle();

    expect(EventLog::count())->toBe(0);
});

test('dispatch trigger job skips when trigger is disabled', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'test.job.disabled',
        'action' => TestJobAction::class,
        'enabled' => false,
        'async' => true,
    ]);

    $job = new DispatchTriggerJob(
        $trigger->id,
        'test.job.disabled',
        [],
    );

    $job->handle();

    expect(EventLog::count())->toBe(0);
});

test('dispatch trigger job has retry configuration', function (): void {
    config()->set('events.retry.tries', 3);
    config()->set('events.retry.backoff', '60,300,900');

    $job = new DispatchTriggerJob('test', 'test.event', []);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300, 900]);
});

test('dispatch trigger job reads retry configuration from config', function (): void {
    config()->set('events.retry.tries', 5);
    config()->set('events.retry.backoff', '30,60,120,300');

    $job = new DispatchTriggerJob('test', 'test.event', []);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([30, 60, 120, 300]);
});

test('dispatch trigger job failed method marks log as failed', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'test.job.failed',
        'action' => TestJobAction::class,
        'enabled' => true,
        'async' => true,
    ]);

    // Manually create an event log to simulate job that created log before failing
    $log = new EventLog([
        'id' => (string) Str::uuid(),
        'trigger_id' => $trigger->id,
        'event' => 'test.job.failed',
        'payload' => [],
        'status' => EventLog::STATUS_DISPATCHED,
    ]);
    $log->save();

    // Use reflection to set eventLogId since it's protected
    $job = new DispatchTriggerJob($trigger->id, 'test.job.failed', []);

    $reflection = new ReflectionProperty(DispatchTriggerJob::class, 'eventLogId');
    $reflection->setValue($job, $log->id);

    $exception = new RuntimeException('Test failure');
    $job->failed($exception);

    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_FAILED)
        ->and($log->error)->toBe('Test failure');
});

test('dispatch trigger job reads queue name from config', function (): void {
    config()->set('events.queue.queue', 'high-priority');

    $job = new DispatchTriggerJob('test', 'test.event', []);

    expect($job->queue)->toBe('high-priority');
});

test('dispatch trigger job reads queue connection from config', function (): void {
    config()->set('events.queue.connection', 'redis');

    $job = new DispatchTriggerJob('test', 'test.event', []);

    expect($job->connection)->toBe('redis');
});

test('dispatch trigger job defaults queue connection to null when config is empty', function (): void {
    config()->set('events.queue.connection', '');

    $job = new DispatchTriggerJob('test', 'test.event', []);

    expect($job->connection)->toBeNull();
});

test('dispatch trigger job failed method handles missing event log gracefully', function (): void {
    $job = new DispatchTriggerJob('test-id', 'test.event', []);

    // eventLogId is null — should not throw
    $job->failed(new RuntimeException('Test failure'));

    expect(true)->toBeTrue(); // Just assert no exception was thrown
});

// Test action for job tests
class TestJobAction implements Triggerable
{
    public function handle(array $payload): void
    {
        // Simple no-op action
    }
}
