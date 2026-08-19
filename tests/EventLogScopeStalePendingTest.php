<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\Models\EventLog;

beforeEach(function (): void {
    $this->app = $this->createApplication();
});

describe('EventLog::scopeStalePending', function (): void {
    test('it returns only pending logs older than the threshold', function (): void {
        $oldLog = new EventLog([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'stale.test',
            'payload' => [],
            'status' => EventLog::STATUS_PENDING,
        ]);
        $oldLog->created_at = Carbon::now()->subHours(2);
        $oldLog->save();

        $recentLog = new EventLog([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'stale.test',
            'payload' => [],
            'status' => EventLog::STATUS_PENDING,
        ]);
        $recentLog->created_at = Carbon::now()->subMinutes(5);
        $recentLog->save();

        $failedLog = new EventLog([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'stale.test',
            'payload' => [],
            'status' => EventLog::STATUS_FAILED,
        ]);
        $failedLog->created_at = Carbon::now()->subHours(3);
        $failedLog->save();

        $threshold = Carbon::now()->subHour();
        $stale = EventLog::stalePending($threshold)->get();

        expect($stale)->toHaveCount(1);
        expect($stale->first()->id)->toBe($oldLog->id);
    });

    test('it returns empty collection when no stale pending logs exist', function (): void {
        $recentLog = new EventLog([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'fresh.test',
            'payload' => [],
            'status' => EventLog::STATUS_PENDING,
        ]);
        $recentLog->created_at = Carbon::now()->subMinutes(1);
        $recentLog->save();

        $threshold = Carbon::now()->subHour();
        $stale = EventLog::stalePending($threshold)->get();

        expect($stale)->toHaveCount(0);
    });

    test('it returns only pending logs even when older failed logs exist', function (): void {
        $pendingLog = new EventLog([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'mixed.test',
            'payload' => [],
            'status' => EventLog::STATUS_PENDING,
        ]);
        $pendingLog->created_at = Carbon::now()->subDays(7);
        $pendingLog->save();

        $failedLog = new EventLog([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'mixed.test',
            'payload' => [],
            'status' => EventLog::STATUS_FAILED,
        ]);
        $failedLog->created_at = Carbon::now()->subDays(7);
        $failedLog->save();

        $threshold = Carbon::now()->subDays(1);
        $stale = EventLog::stalePending($threshold)->get();

        expect($stale)->toHaveCount(1);
        expect($stale->first()->status)->toBe(EventLog::STATUS_PENDING);
    });
});
