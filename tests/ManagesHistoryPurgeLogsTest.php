<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

uses(ZeroBoiler\Events\Tests\TestCase::class);

describe('ManagesHistory purgeLogs', function (): void {
    beforeEach(function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        // Create logs with different statuses and dates
        $this->oldCompleted = EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $this->oldFailed = EventLog::factory()->failed()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $this->oldPending = EventLog::factory()->pending()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $this->recentCompleted = EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $this->recentFailed = EventLog::factory()->failed()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(5),
        ]);
    });

    test('purgeLogs deletes old completed and failed logs by default', function (): void {
        $app = app();
        $em = $app->make(\ZeroBoiler\Events\EventManager::class);

        $deleted = $em->purgeLogs(Carbon::now()->subDays(30), includePending: false);

        expect($deleted)->toBe(2);

        // Old completed and failed should be deleted
        expect(EventLog::find($this->oldCompleted->id))->toBeNull();
        expect(EventLog::find($this->oldFailed->id))->toBeNull();

        // Old pending should remain (includePending = false)
        expect(EventLog::find($this->oldPending->id))->not->toBeNull();

        // Recent logs should remain
        expect(EventLog::find($this->recentCompleted->id))->not->toBeNull();
        expect(EventLog::find($this->recentFailed->id))->not->toBeNull();
    });

    test('purgeLogs with includePending also purges pending/dispatched logs', function (): void {
        $app = app();
        $em = $app->make(\ZeroBoiler\Events\EventManager::class);

        $deleted = $em->purgeLogs(Carbon::now()->subDays(30), includePending: true);

        expect($deleted)->toBe(3); // completed + failed + pending

        expect(EventLog::find($this->oldCompleted->id))->toBeNull();
        expect(EventLog::find($this->oldFailed->id))->toBeNull();
        expect(EventLog::find($this->oldPending->id))->toBeNull();

        // Recent logs should remain
        expect(EventLog::find($this->recentCompleted->id))->not->toBeNull();
        expect(EventLog::find($this->recentFailed->id))->not->toBeNull();
    });

    test('purgeLogs returns zero when no logs are old enough', function (): void {
        $app = app();
        $em = $app->make(\ZeroBoiler\Events\EventManager::class);

        $deleted = $em->purgeLogs(Carbon::now()->subDays(1), includePending: false);

        expect($deleted)->toBe(0);

        // All logs should remain
        expect(EventLog::find($this->oldCompleted->id))->not->toBeNull();
        expect(EventLog::find($this->oldFailed->id))->not->toBeNull();
        expect(EventLog::find($this->recentCompleted->id))->not->toBeNull();
    });

    test('purgeLogs handles empty database gracefully', function (): void {
        // Soft-delete all existing logs first
        EventLog::query()->forceDelete();

        $app = app();
        $em = $app->make(\ZeroBoiler\Events\EventManager::class);

        $deleted = $em->purgeLogs(Carbon::now()->subDays(30), includePending: false);

        expect($deleted)->toBe(0);
    });
});
