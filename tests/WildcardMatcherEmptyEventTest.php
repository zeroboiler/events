<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\WildcardMatcher;

describe('WildcardMatcher empty event edge cases', function (): void {
    test('catch-all * does not match empty string', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    });

    test('catch-all ** does not match empty string', function (): void {
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('single-segment wildcard *.foo does not match empty string', function (): void {
        expect(WildcardMatcher::matches('*.foo', ''))->toBeFalse();
    });

    test('foo.* does not match empty string', function (): void {
        expect(WildcardMatcher::matches('foo.*', ''))->toBeFalse();
    });

    test('findMatchingPatterns returns empty array for empty patterns list', function (): void {
        expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
    });

    test('findMatchingPatterns returns empty array when no pattern matches', function (): void {
        $patterns = ['user.*', 'order.**', 'invoice.*'];
        expect(WildcardMatcher::findMatchingPatterns($patterns, 'payment.received'))->toBe([]);
    });

    test('findMatchingPatterns returns all matching patterns', function (): void {
        $patterns = ['user.*', 'order.placed', 'order.*', '**'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe(['order.placed', 'order.*', '**']);
    });

    test('extractWildcards returns empty array for ** pattern', function (): void {
        expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
    });

    test('extractWildcards returns empty array when segment counts differ', function (): void {
        expect(WildcardMatcher::extractWildcards('order.*', 'order.placed.extra'))->toBe([]);
    });

    test('extractWildcards returns empty array when event does not match pattern', function (): void {
        expect(WildcardMatcher::extractWildcards('user.*.created', 'order.placed.created'))->toBe([]);
    });
});
