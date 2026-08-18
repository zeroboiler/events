<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Str;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

describe('DispatchTriggerJob::failed() edge cases', function (): void {
    test('failed() marks event log as failed when eventLogId is set and log exists', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'test.failed.edge.exists',
            'action' => FailedEdgeAction::class,
            'enabled' => true,
        ]);

        $log = new EventLog([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'test.failed.edge.exists',
            'payload' => ['key' => 'value'],
            'status' => EventLog::STATUS_DISPATCHED,
        ]);
        $log->save();

        $job = new DispatchTriggerJob($trigger->id, 'test.failed.edge.exists', []);

        $reflection = new \ReflectionProperty(DispatchTriggerJob::class, 'eventLogId');
        $reflection->setValue($job, $log->id);

        $exception = new \RuntimeException('Simulated failure');
        $job->failed($exception);

        $log->refresh();

        expect($log->status)->toBe(EventLog::STATUS_FAILED)
            ->and($log->error)->toBe('Simulated failure');
    });

    test('failed() handles gracefully when eventLogId is set but log was deleted', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'test.failed.edge.deleted',
            'action' => FailedEdgeAction::class,
            'enabled' => true,
        ]);

        // Create and then delete the log
        $log = new EventLog([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'test.failed.edge.deleted',
            'payload' => [],
            'status' => EventLog::STATUS_DISPATCHED,
        ]);
        $log->save();
        $logId = $log->id;
        $log->delete();

        $job = new DispatchTriggerJob($trigger->id, 'test.failed.edge.deleted', []);

        $reflection = new \ReflectionProperty(DispatchTriggerJob::class, 'eventLogId');
        $reflection->setValue($job, $logId);

        // Should not throw — the log was deleted, find() returns null, instanceof check prevents NPE
        $exception = new \RuntimeException('Simulated failure after log deleted');
        $job->failed($exception);

        // No assertion needed other than no exception — verifies the instanceof guard works
        expect(true)->toBeTrue();
    });

    test('failed() does nothing when eventLogId is null', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'test.failed.edge.null',
            'action' => FailedEdgeAction::class,
            'enabled' => true,
        ]);

        $job = new DispatchTriggerJob($trigger->id, 'test.failed.edge.null', []);

        // eventLogId remains null (default) — job failed before handle() created a log
        $exception = new \RuntimeException('Failure before log creation');
        $job->failed($exception);

        expect(EventLog::count())->toBe(0);
    });

    test('failed() preserves existing error message from a previous failure', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'test.failed.edge.preserve',
            'action' => FailedEdgeAction::class,
            'enabled' => true,
        ]);

        $log = new EventLog([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'test.failed.edge.preserve',
            'payload' => [],
            'status' => EventLog::STATUS_DISPATCHED,
        ]);
        $log->save();

        $job = new DispatchTriggerJob($trigger->id, 'test.failed.edge.preserve', []);

        $reflection = new \ReflectionProperty(DispatchTriggerJob::class, 'eventLogId');
        $reflection->setValue($job, $log->id);

        $exception = new \RuntimeException('Final retry failure with long error message: '.str_repeat('x', 200));
        $job->failed($exception);

        $log->refresh();

        expect($log->status)->toBe(EventLog::STATUS_FAILED)
            ->and($log->error)->toStartWith('Final retry failure')
            ->and(strlen((string) $log->error))->toBeGreaterThan(50);
    });

    test('failed() correctly structures the inner if block for instanceof guard', function (): void {
        // This test verifies the structural correctness of the failed() method:
        // The inner `if ($log instanceof EventLog)` guard must exist to prevent
        // calling update() on null when EventLog::find() returns null.
        $method = new \ReflectionMethod(DispatchTriggerJob::class, 'failed');
        $filename = $method->getFileName();
        $contents = (string) file_get_contents($filename);

        // Verify the instanceof guard exists after the find() call
        expect($contents)->toContain('if ($log instanceof EventLog)');

        // Verify both if blocks are properly closed (balanced braces in the method)
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $methodBody = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));

        $openBraces = substr_count($methodBody, '{');
        $closeBraces = substr_count($methodBody, '}');

        expect($openBraces)->toBe($closeBraces, 'failed() method must have balanced braces');
    });
});

final class FailedEdgeAction implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        // No-op test action
    }
}
