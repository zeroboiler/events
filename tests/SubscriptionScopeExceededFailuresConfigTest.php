<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\Models\Subscription;

/**
 * Tests for Subscription::scopeExceededFailures reading from config.
 *
 * Verifies that the scope correctly uses the configured max_failures
 * threshold to filter subscriptions.
 *
 * @see \ZeroBoiler\Events\Models\Subscription::scopeExceededFailures()
 *
 * @since 1.0.0
 */
final class SubscriptionScopeExceededFailuresConfigTest extends TestCase
{
    public function test_scope_filters_by_default_config_threshold(): void
    {
        // Default config has max_failures = 10
        Subscription::factory()->create([
            'failure_count' => 10,
            'active' => true,
        ]);

        Subscription::factory()->create([
            'failure_count' => 9,
            'active' => true,
        ]);

        $exceeded = Subscription::exceededFailures()->get();

        expect($exceeded)->toHaveCount(1);
        expect($exceeded->first()->failure_count)->toBe(10);
    }

    public function test_scope_uses_custom_config_threshold(): void
    {
        $config = self::$app->make('config');
        $config->set('events.subscriptions.max_failures', 3);

        Subscription::factory()->create([
            'failure_count' => 3,
            'active' => true,
        ]);

        Subscription::factory()->create([
            'failure_count' => 2,
            'active' => true,
        ]);

        $exceeded = Subscription::exceededFailures()->get();

        expect($exceeded)->toHaveCount(1);
        expect($exceeded->first()->failure_count)->toBe(3);
    }

    public function test_scope_with_string_config_threshold(): void
    {
        $config = self::$app->make('config');
        $config->set('events.subscriptions.max_failures', '5');

        Subscription::factory()->create([
            'failure_count' => 5,
            'active' => true,
        ]);

        Subscription::factory()->create([
            'failure_count' => 4,
            'active' => true,
        ]);

        $exceeded = Subscription::exceededFailures()->get();

        expect($exceeded)->toHaveCount(1);
    }

    public function test_has_exceeded_failures_with_explicit_max(): void
    {
        $sub = Subscription::factory()->create([
            'failure_count' => 5,
            'active' => true,
        ]);

        expect($sub->hasExceededFailures(5))->toBeTrue();
        expect($sub->hasExceededFailures(6))->toBeFalse();
        expect($sub->hasExceededFailures(4))->toBeTrue();
    }

    public function test_has_exceeded_failures_with_null_max_reads_config(): void
    {
        $config = self::$app->make('config');
        $config->set('events.subscriptions.max_failures', 7);

        $sub = Subscription::factory()->create([
            'failure_count' => 7,
            'active' => true,
        ]);

        // No explicit max → reads from config (7)
        expect($sub->hasExceededFailures(null))->toBeTrue();
    }

    public function test_has_exceeded_failures_with_zero_failures(): void
    {
        $sub = Subscription::factory()->create([
            'failure_count' => 0,
            'active' => true,
        ]);

        expect($sub->hasExceededFailures(1))->toBeFalse();
        expect($sub->hasExceededFailures(0))->toBeTrue();
    }
}
