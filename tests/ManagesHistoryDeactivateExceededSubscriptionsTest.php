<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;

/**
 * Tests for ManagesHistory::deactivateExceededSubscriptions().
 *
 * Verifies that subscriptions exceeding the failure threshold are
 * correctly deactivated, and that subscriptions below the threshold
 * remain active.
 */
test('deactivateExceededSubscriptions deactivates subscriptions at or above threshold', function (): void {
    // Create an active subscription with failure_count = 10 (default threshold)
    $sub1 = Subscription::factory()->active()->withFailureCount(10)->create();

    // Create an active subscription with failure_count = 15 (above threshold)
    $sub2 = Subscription::factory()->active()->withFailureCount(15)->create();

    // Create an active subscription with failure_count = 3 (below threshold)
    $sub3 = Subscription::factory()->active()->withFailureCount(3)->create();

    // Create an already-inactive subscription at threshold (should not be counted)
    $sub4 = Subscription::factory()->inactive()->withFailureCount(20)->create();

    $eventManager = app(\ZeroBoiler\Events\EventManager::class);
    $count = $eventManager->deactivateExceededSubscriptions();

    // Only sub1 and sub2 should have been deactivated (sub4 was already inactive)
    expect($count)->toBe(2);

    expect($sub1->fresh()->active)->toBeFalse();
    expect($sub2->fresh()->active)->toBeFalse();
    expect($sub3->fresh()->active)->toBeTrue();
    // sub4 was already inactive — deactivateExceededSubscriptions only queries active()
    expect($sub4->fresh()->active)->toBeFalse();
});

test('deactivateExceededSubscriptions returns zero when no subscriptions exceed threshold', function (): void {
    // Create active subscriptions below the failure threshold
    Subscription::factory()->active()->withFailureCount(0)->create();
    Subscription::factory()->active()->withFailureCount(5)->create();
    Subscription::factory()->active()->withFailureCount(9)->create();

    $eventManager = app(\ZeroBoiler\Events\EventManager::class);
    $count = $eventManager->deactivateExceededSubscriptions();

    expect($count)->toBe(0);
});

test('deactivateExceededSubscriptions respects custom max_failures config', function (): void {
    config()->set('events.subscriptions.max_failures', 5);

    // failure_count = 5 (at threshold)
    $sub1 = Subscription::factory()->active()->withFailureCount(5)->create();

    // failure_count = 4 (below threshold)
    $sub2 = Subscription::factory()->active()->withFailureCount(4)->create();

    $eventManager = app(\ZeroBoiler\Events\EventManager::class);
    $count = $eventManager->deactivateExceededSubscriptions();

    expect($count)->toBe(1);
    expect($sub1->fresh()->active)->toBeFalse();
    expect($sub2->fresh()->active)->toBeTrue();
});

test('deactivateExceededSubscriptions handles empty table gracefully', function (): void {
    $eventManager = app(\ZeroBoiler\Events\EventManager::class);
    $count = $eventManager->deactivateExceededSubscriptions();

    expect($count)->toBe(0);
});
