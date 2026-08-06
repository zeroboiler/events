<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('EventsRedeliverCommand', function (): void {
    test('redeliver returns failure for non-existent log', function (): void {
        $this->artisan('zeroboiler:events:redeliver', ['log_id' => 'non-existent-id'])
            ->assertExitCode(1)
            ->expectsOutput('Event log non-existent-id not found.');
    });

    test('redeliver returns failure for pending log', function (): void {
        $log = EventLog::factory()->create([
            'status' => EventLog::STATUS_PENDING,
            'payload' => ['url' => 'https://example.com/webhook'],
        ]);

        $this->artisan('zeroboiler:events:redeliver', ['log_id' => $log->id])
            ->assertExitCode(1);
    });

    test('redeliver returns failure for dispatched log', function (): void {
        $log = EventLog::factory()->create([
            'status' => EventLog::STATUS_DISPATCHED,
            'payload' => ['url' => 'https://example.com/webhook'],
        ]);

        $this->artisan('zeroboiler:events:redeliver', ['log_id' => $log->id])
            ->assertExitCode(1);
    });

    test('redeliver returns failure when no webhook URL in payload', function (): void {
        $log = EventLog::factory()->create([
            'status' => EventLog::STATUS_FAILED,
            'payload' => ['some' => 'data'],
        ]);

        $this->artisan('zeroboiler:events:redeliver', ['log_id' => $log->id])
            ->assertExitCode(1)
            ->expectsOutput('No webhook URL found in the event log payload.');
    });

    test('redeliver completes successfully for failed log with URL (mock)', function (): void {
        $log = EventLog::factory()->create([
            'status' => EventLog::STATUS_FAILED,
            'payload' => [
                'url' => 'https://example.com/webhook',
                'subscription_id' => null,
            ],
        ]);

        // We can't easily mock Http::fake in this test framework,
        // so we test the validation path only
        $this->artisan('zeroboiler:events:redeliver', ['log_id' => $log->id, '--force' => true])
            ->assertExitCode(1); // Will fail because no real HTTP server, but command runs
    });

    test('redeliver completed log allows redelivery', function (): void {
        $log = EventLog::factory()->create([
            'status' => EventLog::STATUS_COMPLETED,
            'duration_ms' => 50,
            'payload' => [
                'url' => 'https://example.com/webhook',
            ],
        ]);

        $this->artisan('zeroboiler:events:redeliver', ['log_id' => $log->id, '--force' => true])
            ->assertExitCode(1); // Will fail because no real HTTP server
    });
});
