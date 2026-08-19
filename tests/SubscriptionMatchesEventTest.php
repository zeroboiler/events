<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Subscription;

describe('Subscription::matchesEvent', function () {
    it('matches exact event names', function () {
        $sub = new Subscription(['event' => 'order.placed']);
        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.shipped'))->toBeFalse();
        expect($sub->matchesEvent('order'))->toBeFalse();
    });

    it('matches single-segment wildcards via WildcardMatcher', function () {
        $sub = new Subscription(['event' => 'order.*']);
        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.shipped'))->toBeTrue();
        expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
        expect($sub->matchesEvent('order'))->toBeFalse();
    });

    it('matches cross-segment wildcards via WildcardMatcher', function () {
        $sub = new Subscription(['event' => 'order.**']);
        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
        expect($sub->matchesEvent('order.a.b.c'))->toBeTrue();
    });

    it('returns false for empty event string when pattern is non-empty', function () {
        $sub = new Subscription(['event' => 'order.placed']);
        expect($sub->matchesEvent(''))->toBeFalse();
    });

    it('delegates correctly for multi-wildcard patterns', function () {
        $sub = new Subscription(['event' => '*.order.*']);
        expect($sub->matchesEvent('user.order.created'))->toBeTrue();
        expect($sub->matchesEvent('user.order'))->toBeFalse();
        expect($sub->matchesEvent('order.created'))->toBeFalse();
    });
});
