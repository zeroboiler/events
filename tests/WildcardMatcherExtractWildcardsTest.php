<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\WildcardMatcher;

describe('WildcardMatcher extractWildcards', function () {
    it('extracts single wildcard values', function () {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        expect($result)->toBe(['profile']);
    });

    it('extracts multiple wildcard values', function () {
        $result = WildcardMatcher::extractWildcards('*.order.*', 'new.order.confirmed');

        expect($result)->toBe(['new', 'confirmed']);
    });

    it('returns empty array for non-matching pattern', function () {
        $result = WildcardMatcher::extractWildcards('user.*.deleted', 'user.profile.created');

        expect($result)->toBe([]);
    });

    it('returns empty array when pattern contains ** (cross-segment)', function () {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        expect($result)->toBe([]);
    });

    it('returns empty array when segment counts differ', function () {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.action.created');

        expect($result)->toBe([]);
    });

    it('returns empty array for exact pattern with no wildcards', function () {
        $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');

        expect($result)->toBe([]);
    });

    it('returns empty array for catch-all pattern *', function () {
        $result = WildcardMatcher::extractWildcards('*', 'order.placed');

        expect($result)->toBe([]);
    });

    it('returns empty array for catch-all pattern **', function () {
        $result = WildcardMatcher::extractWildcards('**', 'order.placed');

        expect($result)->toBe([]);
    });

    it('extracts wildcard from empty segment', function () {
        // Pattern with * matching an empty string between dots
        $result = WildcardMatcher::extractWildcards('order.*.placed', 'order..placed');

        expect($result)->toBe(['']);
    });

    it('extracts numeric segment values', function () {
        $result = WildcardMatcher::extractWildcards('region.*.zone', 'region.42.zone');

        expect($result)->toBe(['42']);
    });

    it('handles patterns with dots in extracted values', function () {
        // * only matches within a single segment, so dots should not appear
        $result = WildcardMatcher::extractWildcards('a.*.b', 'a.c.b');

        expect($result)->toBe(['c']);
    });
});
