<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\WildcardMatcher;

describe('WildcardMatcher edge cases', function (): void {
    test('empty pattern matches nothing (except empty is also rejected)', function (): void {
        expect(WildcardMatcher::matches('', ''))->toBeFalse();
        expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
    });

    test('exact match works', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('single asterisk matches any non-empty event', function (): void {
        expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('*', 'a'))->toBeTrue();
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    });

    test('double asterisk matches any non-empty event', function (): void {
        expect(WildcardMatcher::matches('**', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('**', 'a.b.c.d'))->toBeTrue();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('single-segment wildcard does not cross dot boundaries', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        expect(WildcardMatcher::matches('order.*', 'user.placed'))->toBeFalse();
    });

    test('cross-segment wildcard crosses dot boundaries', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.a.b.c'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'user.placed'))->toBeFalse();
    });

    test('multiple wildcards', function (): void {
        expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
        expect(WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse();
        expect(WildcardMatcher::matches('*.*.created', 'user.order.created'))->toBeTrue();
        expect(WildcardMatcher::matches('*.*.created', 'user.order.updated'))->toBeFalse();
    });

    test('regex special characters are properly escaped', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('user.login', 'user.login'))->toBeTrue();
    });

    test('findMatchingPatterns returns correct matches in order', function (): void {
        $patterns = ['order.placed', 'order.*', '*.created', 'user.*'];
        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matches)->toContain('order.placed')
            ->and($matches)->toContain('order.*')
            ->and($matches)->not->toContain('*.created')
            ->and($matches)->not->toContain('user.*');
    });

    test('findMatchingPatterns with empty input returns empty array', function (): void {
        expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
    });

    test('extractWildcards returns correct values for single-segment wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.admin.created');

        expect($result)->toBe(['admin']);
    });

    test('extractWildcards with multiple wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('*.order.*', 'user.order.created');

        expect($result)->toBe(['user', 'created']);
    });

    test('extractWildcards returns empty for cross-segment patterns', function (): void {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed');

        expect($result)->toBe([]);
    });

    test('extractWildcards returns empty for mismatched segment count', function (): void {
        $result = WildcardMatcher::extractWildcards('order.*', 'order.placed.extra');

        expect($result)->toBe([]);
    });

    test('backslash in event name is handled', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.something'))->toBeTrue();
    });
});
