<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

test('EventLog scopeStalePending returns only pending logs older than threshold', function (): void {
    // Create triggers
    $trigger = Trigger::factory()->enabled()->create();

    // Create a stale pending log (old)
    $staleLog = EventLog::factory()->forTrigger($trigger->id)->pending()->create([
        'created_at' => Carbon::now()->subHours(2),
    ]);

    // Create a fresh pending log (should not match)
    $freshLog = EventLog::factory()->forTrigger($trigger->id)->pending()->create([
        'created_at' => Carbon::now()->subMinutes(5),
    ]);

    // Create an old completed log (should not match — not pending)
    $completedLog = EventLog::factory()->forTrigger($trigger->id)->completed()->create([
        'created_at' => Carbon::now()->subHours(2),
    ]);

    $threshold = Carbon::now()->subHour();
    $results = EventLog::stalePending($threshold)->get();

    expect($results->count())->toBe(1);
    expect($results->first()->id)->toBe($staleLog->id);
});

test('EventLog scopeStalePending returns empty when no stale logs exist', function (): void {
    $trigger = Trigger::factory()->enabled()->create();

    EventLog::factory()->forTrigger($trigger->id)->pending()->create([
        'created_at' => Carbon::now()->subMinutes(5),
    ]);

    $threshold = Carbon::now()->subHour();
    $results = EventLog::stalePending($threshold)->get();

    expect($results->count())->toBe(0);
});

test('Subscription scopeExceededFailures returns only active subs above threshold', function (): void {
    // Default max_failures is 10
    $sub = Subscription::factory()->active()->withFailureCount(15)->create();
    $subOk = Subscription::factory()->active()->withFailureCount(3)->create();
    $subInactive = Subscription::factory()->inactive()->withFailureCount(20)->create();

    $results = Subscription::active()->exceededFailures()->get();

    expect($results->count())->toBe(1);
    expect($results->first()->id)->toBe($sub->id);
});

test('Subscription scopeExceededFailures reads config threshold', function (): void {
    config()->set('events.subscriptions.max_failures', 5);

    $sub = Subscription::factory()->active()->withFailureCount(6)->create();
    $subOk = Subscription::factory()->active()->withFailureCount(4)->create();

    $results = Subscription::active()->exceededFailures()->get();

    expect($results->count())->toBe(1);
    expect($results->first()->id)->toBe($sub->id);

    // Reset config
    config()->set('events.subscriptions.max_failures', 10);
});

test('Subscription scopeExceededFailures handles non-int config', function (): void {
    config()->set('events.subscriptions.max_failures', 'not-a-number');

    // Should fall back to default 10
    $sub = Subscription::factory()->active()->withFailureCount(11)->create();
    $subOk = Subscription::factory()->active()->withFailureCount(9)->create();

    $results = Subscription::active()->exceededFailures()->get();

    expect($results->count())->toBe(1);
    expect($results->first()->id)->toBe($sub->id);

    config()->set('events.subscriptions.max_failures', 10);
});

test('getStalePendingLogs returns collection with eager-loaded triggers', function (): void {
    $trigger = Trigger::factory()->enabled()->create();

    EventLog::factory()->forTrigger($trigger->id)->pending()->create([
        'created_at' => Carbon::now()->subHours(2),
    ]);

    $stale = app(ZeroBoiler\Events\EventManager::class)->getStalePendingLogs(
        Carbon::now()->subHour(),
    );

    expect($stale->count())->toBe(1);
    expect($stale->first()->relationLoaded('trigger'))->toBeTrue();
});

test('getStalePendingLogs respects limit', function (): void {
    $trigger = Trigger::factory()->enabled()->create();

    EventLog::factory()->forTrigger($trigger->id)->pending()->count(5)->create([
        'created_at' => Carbon::now()->subHours(2),
    ]);

    $stale = app(ZeroBoiler\Events\EventManager::class)->getStalePendingLogs(
        Carbon::now()->subHour(),
        limit: 3,
    );

    expect($stale->count())->toBe(3);
});

test('deactivateExceededSubscriptions deactivates all exceeded subs and returns count', function (): void {
    Subscription::factory()->active()->withFailureCount(15)->create();
    Subscription::factory()->active()->withFailureCount(20)->create();
    Subscription::factory()->active()->withFailureCount(3)->create();

    $count = app(ZeroBoiler\Events\EventManager::class)->deactivateExceededSubscriptions();

    expect($count)->toBe(2);
    expect(Subscription::active()->count())->toBe(1);
});

test('deactivateExceededSubscriptions returns 0 when none exceeded', function (): void {
    Subscription::factory()->active()->withFailureCount(3)->create();
    Subscription::factory()->active()->withFailureCount(5)->create();

    $count = app(ZeroBoiler\Events\EventManager::class)->deactivateExceededSubscriptions();

    expect($count)->toBe(0);
});

test('deactivateExceededSubscriptions skips inactive subs even if exceeded', function (): void {
    // Already inactive — should NOT count
    Subscription::factory()->inactive()->withFailureCount(20)->create();

    $count = app(ZeroBoiler\Events\EventManager::class)->deactivateExceededSubscriptions();

    expect($count)->toBe(0);
});

test('scopeStalePending composable with other scopes', function (): void {
    $trigger = Trigger::factory()->enabled()->create();

    EventLog::factory()->forTrigger($trigger->id)->pending()->create([
        'created_at' => Carbon::now()->subHours(2),
    ]);
    EventLog::factory()->forTrigger($trigger->id)->failed()->create([
        'created_at' => Carbon::now()->subHours(2),
    ]);

    // stalePending + with('trigger') chain
    $results = EventLog::with('trigger')
        ->stalePending(Carbon::now()->subHour())
        ->get();

    expect($results->count())->toBe(1);
    expect($results->first()->status)->toBe('pending');
    expect($results->first()->relationLoaded('trigger'))->toBeTrue();
});
