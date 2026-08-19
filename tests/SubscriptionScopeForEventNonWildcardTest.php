<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Subscription;


describe('Subscription scopeForEvent non-wildcard path', function () {
    it('returns exact match and wildcard subscriptions for non-wildcard event', function () {
        // Create exact match
        Subscription::factory()->create([
            'event' => 'order.placed',
            'active' => true,
        ]);

        // Create a wildcard subscription that should also match
        Subscription::factory()->create([
            'event' => 'order.*',
            'active' => true,
        ]);

        // Create unrelated subscription that should NOT match
        Subscription::factory()->create([
            'event' => 'user.created',
            'active' => true,
        ]);

        $results = Subscription::forEvent('order.placed')->get();

        expect($results->count())->toBeGreaterThanOrEqual(2);

        // All returned subscriptions should have an event that matches 'order.placed'
        foreach ($results as $sub) {
            expect($sub->event)->toBe('order.placed');
        }
    });

    it('returns only wildcard subscriptions when no exact match exists', function () {
        Subscription::factory()->create([
            'event' => 'invoice.*',
            'active' => true,
        ]);

        Subscription::factory()->create([
            'event' => 'unrelated.event',
            'active' => true,
        ]);

        $results = Subscription::forEvent('invoice.paid')->get();

        // Should find the wildcard match
        expect($results->count())->toBeGreaterThanOrEqual(1);

        // At least one should be the wildcard subscription
        $found = $results->first(fn (Subscription $s): bool => $s->event === 'invoice.*');
        expect($found)->not->toBeNull();
    });

    it('returns empty when nothing matches', function () {
        Subscription::factory()->create([
            'event' => 'completely.different',
            'active' => true,
        ]);

        $results = Subscription::forEvent('no.match.event')->get();

        expect($results->count())->toBe(0);
    });

    it('uses wildcard LIKE pattern for wildcard events', function () {
        Subscription::factory()->create([
            'event' => 'prefix.*',
            'active' => true,
        ]);

        Subscription::factory()->create([
            'event' => 'prefix.specific',
            'active' => true,
        ]);

        // Wildcard pattern should use LIKE for DB-level matching
        $results = Subscription::forEvent('prefix.anything')->get();

        // Should find the wildcard subscription via LIKE
        expect($results->count())->toBeGreaterThanOrEqual(1);
    });
});
