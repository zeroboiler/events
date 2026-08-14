<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\Models\Subscription;

/**
 * Tests for Subscription::recordDelivery() atomic increment fix.
 *
 * Verifies that recordDelivery() uses atomic increment() instead of
 * $this->delivery_count + 1 to prevent race conditions in concurrent
 * webhook deliveries.
 */
final class SubscriptionRecordDeliveryAtomicTest extends TestCase
{
    public function test_record_delivery_uses_atomic_increment(): void
    {
        $subscription = Subscription::factory()->create([
            'delivery_count' => 5,
            'last_fired_at' => null,
        ]);

        $subscription->recordDelivery();

        // Refresh from database to get actual persisted value
        $subscription->refresh();

        expect($subscription->delivery_count)->toBe(6);
        expect($subscription->last_fired_at)->not->toBeNull();
    }

    public function test_record_delivery_increments_from_zero(): void
    {
        $subscription = Subscription::factory()->create([
            'delivery_count' => 0,
        ]);

        $subscription->recordDelivery();
        $subscription->refresh();

        expect($subscription->delivery_count)->toBe(1);
    }

    public function test_record_delivery_multiple_times(): void
    {
        $subscription = Subscription::factory()->create([
            'delivery_count' => 0,
        ]);

        $subscription->recordDelivery();
        $subscription->refresh();
        expect($subscription->delivery_count)->toBe(1);

        $subscription->recordDelivery();
        $subscription->refresh();
        expect($subscription->delivery_count)->toBe(2);

        $subscription->recordDelivery();
        $subscription->refresh();
        expect($subscription->delivery_count)->toBe(3);
    }

    public function test_record_delivery_updates_last_fired_at(): void
    {
        $before = Carbon::now()->subMinute();
        $subscription = Subscription::factory()->create([
            'last_fired_at' => $before,
        ]);

        // Small delay to ensure timestamp differs
        usleep(10000);

        $subscription->recordDelivery();
        $subscription->refresh();

        expect($subscription->last_fired_at->greaterThan($before))->toBeTrue();
    }

    public function test_record_delivery_is_independent_of_in_memory_count(): void
    {
        // Create subscription with delivery_count = 10
        $subscription = Subscription::factory()->create([
            'delivery_count' => 10,
        ]);

        // Simulate stale in-memory state (count was updated externally to 15)
        Subscription::where('id', $subscription->id)->update([
            'delivery_count' => 15,
        ]);

        // The in-memory model still has 10, but increment() is atomic so
        // it will go from the DB value 15 → 16, not from stale 10 → 11
        $subscription->recordDelivery();
        $subscription->refresh();

        expect($subscription->delivery_count)->toBe(16);
    }
}
