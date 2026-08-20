<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Edge cases for EventManager::getStats() that complement
 * EventHistoryStatsTest.php.
 */
test('getStats returns zero counts when no data exists', function (): void {
    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);

    $stats = $manager->getStats();

    expect($stats)->toBeArray()
        ->and($stats['total_logs'])->toBe(0)
        ->and($stats['total_triggers'])->toBe(0)
        ->and($stats['active_triggers'])->toBe(0)
        ->and($stats['completed'])->toBe(0)
        ->and($stats['failed'])->toBe(0)
        ->and($stats['pending'])->toBe(0)
        ->and($stats['dispatched'])->toBe(0)
        ->and($stats['success_rate'])->toBeNull()
        ->and($stats['failure_rate'])->toBeNull()
        ->and($stats['avg_duration_ms'])->toBeNull()
        ->and($stats['top_events'])->toBe([])
        ->and($stats['top_failed_events'])->toBe([]);
});

test('getStats with since filter only includes recent logs', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'stats.test',
        'action' => 'ZeroBoiler\\Events\\Tests\\Actions\\NullAction',
        'enabled' => true,
    ]);

    // Create a completed log
    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'stats.test',
        'status' => EventLog::STATUS_COMPLETED,
        'duration_ms' => 100,
        'created_at' => Carbon::now()->subHours(1),
    ]);

    // Create an old log that should be filtered out
    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'stats.old',
        'status' => EventLog::STATUS_COMPLETED,
        'duration_ms' => 200,
        'created_at' => Carbon::now()->subDays(7),
    ]);

    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);

    $stats = $manager->getStats(since: Carbon::now()->subHours(2));

    expect($stats['total_logs'])->toBe(1)
        ->and($stats['completed'])->toBe(1)
        ->and($stats['avg_duration_ms'])->toBe(100.0);
});

test('getStats calculates correct success rate', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'rate.test',
        'action' => 'ZeroBoiler\\Events\\Tests\\Actions\\NullAction',
        'enabled' => true,
    ]);

    // 3 completed, 1 failed
    for ($i = 0; $i < 3; $i++) {
        EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'event' => 'rate.test',
            'duration_ms' => 50,
        ]);
    }

    EventLog::factory()->failed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'rate.test',
    ]);

    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);
    $stats = $manager->getStats();

    expect($stats['completed'])->toBe(3)
        ->and($stats['failed'])->toBe(1)
        ->and($stats['success_rate'])->toBe(75.0)
        ->and($stats['failure_rate'])->toBe(25.0)
        ->and($stats['avg_duration_ms'])->toBe(50.0);
});

test('getStats returns null rates when no settled logs exist', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'pending.test',
        'action' => 'ZeroBoiler\\Events\\Tests\\Actions\\NullAction',
        'enabled' => true,
    ]);

    EventLog::factory()->pending()->create([
        'trigger_id' => $trigger->id,
        'event' => 'pending.test',
    ]);

    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'pending.test',
        'status' => EventLog::STATUS_DISPATCHED,
    ]);

    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);
    $stats = $manager->getStats();

    expect($stats['pending'])->toBe(1)
        ->and($stats['dispatched'])->toBe(1)
        ->and($stats['success_rate'])->toBeNull()
        ->and($stats['failure_rate'])->toBeNull();
});

test('getStats top_events limited to 10', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'top.test',
        'action' => 'ZeroBoiler\\Events\\Tests\\Actions\\NullAction',
        'enabled' => true,
    ]);

    // Create 15 different events
    for ($i = 0; $i < 15; $i++) {
        EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'event' => "top.event.{$i}",
            'duration_ms' => 10,
        ]);
    }

    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);
    $stats = $manager->getStats();

    expect($stats['top_events'])->toHaveCount(10);
});
