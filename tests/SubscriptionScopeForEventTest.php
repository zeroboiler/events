<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\WildcardMatcher;

uses(ZeroBoiler\Events\Tests\TestCase::class);

describe('Subscription scopeForEvent', function (): void {
    beforeEach(function (): void {
        $this->subscription = Subscription::factory()->create([
            'event' => 'order.placed',
        ]);
        $this->wildcardSub = Subscription::factory()->create([
            'event' => 'order.*',
        ]);
        $this->crossSub = Subscription::factory()->create([
            'event' => 'order.**',
        ]);
        $this->otherSub = Subscription::factory()->create([
            'event' => 'user.created',
        ]);
    });

    test('exact match returns correct subscription', function (): void {
        $results = Subscription::forEvent('order.placed')->get();

        expect($results)->toHaveCount(3); // exact + order.* + order.**
        expect($results->pluck('id')->toArray())
            ->toContain($this->subscription->id)
            ->toContain($this->wildcardSub->id)
            ->toContain($this->crossSub->id);
    });

    test('wildcard match returns wildcard subscriptions', function (): void {
        $results = Subscription::forEvent('order.shipped')->get();

        expect($results)->toHaveCount(2); // order.* + order.**
        expect($results->pluck('id')->toArray())
            ->not->toContain($this->subscription->id)
            ->toContain($this->wildcardSub->id)
            ->toContain($this->crossSub->id);
    });

    test('no match returns empty collection for unrelated event', function (): void {
        $results = Subscription::forEvent('invoice.paid')->get();

        expect($results)->toHaveCount(0);
    });

    test('forEvent uses wildcardToLike for wildcard patterns', function (): void {
        $results = Subscription::forEvent('order.*')->get();

        // Should match 'order.*' (via LIKE %*%) and 'order.placed' (via LIKE)
        // and 'order.**' (via LIKE %*%) and not 'user.created'
        expect($results)->toHaveCount(3);
        expect($results->pluck('id')->toArray())
            ->toContain($this->subscription->id)
            ->toContain($this->wildcardSub->id)
            ->toContain($this->crossSub->id);
    });

    test('forEvent with non-wildcard returns only exact match plus wildcards', function (): void {
        $results = Subscription::forEvent('user.created')->get();

        // Exact match 'user.created' + wildcard patterns containing *
        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($this->otherSub->id);
    });

    test('forEvent scope returns Builder for chaining', function (): void {
        $query = Subscription::forEvent('order.placed')->active();

        expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });
});
