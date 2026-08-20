<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;
use ZeroBoiler\Events\WildcardMatcher;

uses(TestCase::class);

describe('WildcardMatcher::extractWildcards — multiple wildcards', function (): void {
    test('extracts multiple single-segment wildcards from a pattern', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
        expect($result)->toBe(['profile']);
    });

    test('extracts two wildcards from a three-segment pattern with two stars', function (): void {
        $result = WildcardMatcher::extractWildcards('*.*.created', 'user.profile.created');
        expect($result)->toBe(['user', 'profile']);
    });

    test('extracts three wildcards from a four-segment pattern', function (): void {
        $result = WildcardMatcher::extractWildcards('*.*.*.event', 'a.b.c.event');
        expect($result)->toBe(['a', 'b', 'c']);
    });

    test('returns empty array for cross-segment wildcard', function (): void {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');
        expect($result)->toBe([]);
    });

    test('returns empty array when segment counts differ', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.orders.created');
        expect($result)->toBe([]);
    });

    test('returns empty array when pattern does not match event', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.deleted', 'user.profile.created');
        expect($result)->toBe([]);
    });

    test('returns empty array for pattern with no wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('user.profile.created', 'user.profile.created');
        expect($result)->toBe([]);
    });

    test('extracts wildcard from mixed literal and wildcard segments', function (): void {
        $result = WildcardMatcher::extractWildcards('order.*.item.*', 'order.abc.item.def');
        expect($result)->toBe(['abc', 'def']);
    });
});
