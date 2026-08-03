<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    $this->manager = app(EventManager::class);

    // Clean slate
    EventLog::query()->delete();
    Trigger::query()->delete();
});

// ---------------------------------------------------------------------------
// getEventHistory()
// ---------------------------------------------------------------------------

describe('getEventHistory', function (): void {
    it('returns all logs when no filters provided', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(5)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
        ]);

        $history = $this->manager->getEventHistory();

        expect($history)->toHaveCount(5);
    });

    it('respects the limit parameter', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(10)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
        ]);

        $history = $this->manager->getEventHistory(limit: 3);

        expect($history)->toHaveCount(3);
    });

    it('filters by exact event name', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
        ]);
        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.shipped',
        ]);

        $history = $this->manager->getEventHistory(event: 'order.placed');

        expect($history)->toHaveCount(3);
        expect($history->every(fn (EventLog $log): bool => $log->event === 'order.placed'))->toBeTrue();
    });

    it('filters by wildcard event name', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
        ]);
        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.shipped',
        ]);
        EventLog::factory()->count(1)->create([
            'trigger_id' => $trigger->id,
            'event' => 'user.created',
        ]);

        $history = $this->manager->getEventHistory(event: 'order.*');

        expect($history)->toHaveCount(5);
        expect($history->every(fn (EventLog $log): bool => str_starts_with($log->event, 'order.')))->toBeTrue();
    });

    it('filters by status', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
        ]);
        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_FAILED,
        ]);

        $history = $this->manager->getEventHistory(status: EventLog::STATUS_FAILED);

        expect($history)->toHaveCount(2);
        expect($history->every(fn (EventLog $log): bool => $log->status === EventLog::STATUS_FAILED))->toBeTrue();
    });

    it('filters by trigger ID', function (): void {
        $trigger1 = Trigger::factory()->create();
        $trigger2 = Trigger::factory()->create();

        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger1->id,
        ]);
        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger2->id,
        ]);

        $history = $this->manager->getEventHistory(triggerId: $trigger1->id);

        expect($history)->toHaveCount(3);
        expect($history->every(fn (EventLog $log): bool => $log->trigger_id === $trigger1->id))->toBeTrue();
    });

    it('combines multiple filters', function (): void {
        $trigger1 = Trigger::factory()->create();
        $trigger2 = Trigger::factory()->create();

        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger1->id,
            'event' => 'order.placed',
            'status' => EventLog::STATUS_COMPLETED,
        ]);
        EventLog::factory()->count(1)->create([
            'trigger_id' => $trigger1->id,
            'event' => 'order.placed',
            'status' => EventLog::STATUS_FAILED,
        ]);
        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger2->id,
            'event' => 'order.placed',
            'status' => EventLog::STATUS_COMPLETED,
        ]);

        $history = $this->manager->getEventHistory(
            event: 'order.placed',
            status: EventLog::STATUS_COMPLETED,
            triggerId: $trigger1->id,
        );

        expect($history)->toHaveCount(2);
    });

    it('returns empty collection when no matches', function (): void {
        $history = $this->manager->getEventHistory(event: 'nonexistent.event');

        expect($history)->toBeEmpty();
    });

    it('eager loads trigger relationship', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
        ]);

        $history = $this->manager->getEventHistory();

        expect($history->first()->relationLoaded('trigger'))->toBeTrue();
    });

    it('orders results by latest first', function (): void {
        $trigger = Trigger::factory()->create();

        $old = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subHour(),
        ]);
        $new = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now(),
        ]);

        $history = $this->manager->getEventHistory();

        expect($history->first()->id)->toBe($new->id);
        expect($history->last()->id)->toBe($old->id);
    });
});

// ---------------------------------------------------------------------------
// getStats()
// ---------------------------------------------------------------------------

describe('getStats', function (): void {
    it('returns zero counts for empty database', function (): void {
        $stats = $this->manager->getStats();

        expect($stats)->toMatchArray([
            'total_logs' => 0,
            'total_triggers' => 0,
            'active_triggers' => 0,
            'completed' => 0,
            'failed' => 0,
            'pending' => 0,
            'dispatched' => 0,
            'success_rate' => null,
            'failure_rate' => null,
            'avg_duration_ms' => null,
        ]);
    });

    it('counts logs by status correctly', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(5)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
        ]);
        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_FAILED,
        ]);
        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_PENDING,
        ]);
        EventLog::factory()->count(1)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_DISPATCHED,
        ]);

        $stats = $this->manager->getStats();

        expect($stats['total_logs'])->toBe(11);
        expect($stats['completed'])->toBe(5);
        expect($stats['failed'])->toBe(3);
        expect($stats['pending'])->toBe(2);
        expect($stats['dispatched'])->toBe(1);
    });

    it('calculates success and failure rates correctly', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(7)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
        ]);
        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_FAILED,
        ]);

        $stats = $this->manager->getStats();

        // settled = 7+3 = 10, success = 70%, failure = 30%
        expect($stats['success_rate'])->toBe(70.0);
        expect($stats['failure_rate'])->toBe(30.0);
    });

    it('returns null rates when no settled logs', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_PENDING,
        ]);

        $stats = $this->manager->getStats();

        expect($stats['success_rate'])->toBeNull();
        expect($stats['failure_rate'])->toBeNull();
    });

    it('calculates average duration from completed logs', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
            'duration_ms' => 100,
        ]);
        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
            'duration_ms' => 200,
        ]);
        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
            'duration_ms' => 300,
        ]);

        $stats = $this->manager->getStats();

        expect($stats['avg_duration_ms'])->toBe(200.0);
    });

    it('returns null avg duration when no completed logs with duration', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
            'duration_ms' => null,
        ]);

        $stats = $this->manager->getStats();

        expect($stats['avg_duration_ms'])->toBeNull();
    });

    it('counts total and active triggers', function (): void {
        Trigger::factory()->create(['enabled' => true]);
        Trigger::factory()->create(['enabled' => true]);
        Trigger::factory()->create(['enabled' => false]);

        $stats = $this->manager->getStats();

        expect($stats['total_triggers'])->toBe(3);
        expect($stats['active_triggers'])->toBe(2);
    });

    it('returns top events by fire count', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(5)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
        ]);
        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger->id,
            'event' => 'user.created',
        ]);

        $stats = $this->manager->getStats();

        expect($stats['top_events'])->toHaveCount(2);
        expect($stats['top_events'][0])->toMatchArray([
            'event' => 'order.placed',
            'count' => 5,
        ]);
        expect($stats['top_events'][1])->toMatchArray([
            'event' => 'user.created',
            'count' => 3,
        ]);
    });

    it('returns top failed events', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(4)->create([
            'trigger_id' => $trigger->id,
            'event' => 'payment.failed',
            'status' => EventLog::STATUS_FAILED,
        ]);
        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
            'status' => EventLog::STATUS_COMPLETED,
        ]);
        EventLog::factory()->count(1)->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
            'status' => EventLog::STATUS_FAILED,
        ]);

        $stats = $this->manager->getStats();

        expect($stats['top_failed_events'])->toHaveCount(2);
        expect($stats['top_failed_events'][0])->toMatchArray([
            'event' => 'payment.failed',
            'count' => 4,
        ]);
    });

    it('respects the since parameter', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->count(3)->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now()->subDays(10),
        ]);
        EventLog::factory()->count(2)->create([
            'trigger_id' => $trigger->id,
            'created_at' => Carbon::now(),
        ]);

        $stats = $this->manager->getStats(since: Carbon::now()->subDays(5));

        expect($stats['total_logs'])->toBe(2);
    });
});

// ---------------------------------------------------------------------------
// purgeLogs()
// ---------------------------------------------------------------------------

describe('purgeLogs', function (): void {
    it('deletes completed and failed logs older than threshold', function (): void {
        $trigger = Trigger::factory()->create();

        $oldCompleted = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
            'created_at' => Carbon::now()->subDays(40),
        ]);
        $oldFailed = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_FAILED,
            'created_at' => Carbon::now()->subDays(40),
        ]);
        $recentCompleted = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $deleted = $this->manager->purgeLogs(Carbon::now()->subDays(30));

        expect($deleted)->toBe(2);
        expect(EventLog::find($oldCompleted->id))->toBeNull();
        expect(EventLog::find($oldFailed->id))->toBeNull();
        expect(EventLog::find($recentCompleted->id))->not->toBeNull();
    });

    it('does not delete pending or dispatched logs by default', function (): void {
        $trigger = Trigger::factory()->create();

        $oldPending = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_PENDING,
            'created_at' => Carbon::now()->subDays(40),
        ]);
        $oldDispatched = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_DISPATCHED,
            'created_at' => Carbon::now()->subDays(40),
        ]);

        $deleted = $this->manager->purgeLogs(Carbon::now()->subDays(30));

        expect($deleted)->toBe(0);
        expect(EventLog::find($oldPending->id))->not->toBeNull();
        expect(EventLog::find($oldDispatched->id))->not->toBeNull();
    });

    it('deletes pending logs when includePending is true', function (): void {
        $trigger = Trigger::factory()->create();

        $oldPending = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_PENDING,
            'created_at' => Carbon::now()->subDays(40),
        ]);
        $oldDispatched = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_DISPATCHED,
            'created_at' => Carbon::now()->subDays(40),
        ]);

        $deleted = $this->manager->purgeLogs(Carbon::now()->subDays(30), includePending: true);

        expect($deleted)->toBe(2);
        expect(EventLog::find($oldPending->id))->toBeNull();
        expect(EventLog::find($oldDispatched->id))->toBeNull();
    });

    it('returns zero when nothing to purge', function (): void {
        $trigger = Trigger::factory()->create();

        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_COMPLETED,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $deleted = $this->manager->purgeLogs(Carbon::now()->subDays(30));

        expect($deleted)->toBe(0);
    });

    it('handles empty database gracefully', function (): void {
        $deleted = $this->manager->purgeLogs(Carbon::now()->subDays(30));

        expect($deleted)->toBe(0);
    });
});
