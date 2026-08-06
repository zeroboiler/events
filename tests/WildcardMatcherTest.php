<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\WildcardMatcher;

test('matches exact event with exact pattern', function (): void {
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))
        ->toBeTrue();
});

test('does not match different event', function (): void {
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))
        ->toBeFalse();
});

test('single-segment wildcard matches one segment', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.*', 'order.shipped'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.*', 'order.placed.extra'))
        ->toBeFalse();
});

test('cross-segment wildcard matches across segments', function (): void {
    expect(WildcardMatcher::matches('order.**', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.**', 'order.placed.extra'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.**', 'order.a.b.c.d'))
        ->toBeTrue();
});

test('catch-all wildcard matches everything except empty', function (): void {
    expect(WildcardMatcher::matches('*', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*', 'anything'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*', 'a.b.c.d.e'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*', ''))
        ->toBeFalse();
});

test('double-star catch-all matches everything except empty', function (): void {
    expect(WildcardMatcher::matches('**', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('**', 'a.b.c.d'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('**', ''))
        ->toBeFalse();
});

test('multiple wildcards in pattern', function (): void {
    expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*.order.*', 'admin.order.deleted'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*.order.*', 'user.order.a.b'))
        ->toBeFalse();
});

test('wildcard does not match empty segment', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.'))
        ->toBeFalse()
        ->and(WildcardMatcher::matches('order.*', 'order'))
        ->toBeFalse();
});

test('pattern with no wildcards matches exactly', function (): void {
    expect(WildcardMatcher::matches('user.created', 'user.created'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('user.created', 'user.deleted'))
        ->toBeFalse();
});

test('findMatchingPatterns returns matching patterns', function (): void {
    $patterns = ['order.*', 'user.created', '*.payment.*'];

    expect(WildcardMatcher::findMatchingPatterns($patterns, 'order.placed'))
        ->toBe(['order.*'])
        ->and(WildcardMatcher::findMatchingPatterns($patterns, 'user.created'))
        ->toBe(['user.created'])
        ->and(WildcardMatcher::findMatchingPatterns($patterns, 'stripe.payment.received'))
        ->toBe(['*.payment.*']);
});

test('findMatchingPatterns returns empty array for no matches', function (): void {
    expect(WildcardMatcher::findMatchingPatterns(['order.*', 'user.*'], 'payment.received'))
        ->toBeEmpty();
});

test('findMatchingPatterns returns all matching patterns for catch-all', function (): void {
    $patterns = ['order.placed', '*', 'user.*'];

    expect(WildcardMatcher::findMatchingPatterns($patterns, 'order.placed'))
        ->toBe(['order.placed', '*']);
});

test('extractWildcards extracts single-segment wildcards', function (): void {
    expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
        ->toBe(['profile'])
        ->and(WildcardMatcher::extractWildcards('*.order.*', 'admin.order.urgent'))
        ->toBe(['admin', 'urgent']);
});

test('extractWildcards returns empty for cross-segment wildcards', function (): void {
    expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))
        ->toBeEmpty();
});

test('extractWildcards returns empty when segments count differs', function (): void {
    expect(WildcardMatcher::extractWildcards('order.*', 'order.placed.extra'))
        ->toBeEmpty();
});

test('extractWildcards returns empty when pattern does not match', function (): void {
    expect(WildcardMatcher::extractWildcards('user.*.deleted', 'user.profile.created'))
        ->toBeEmpty();
});

test('extractWildcards returns empty array when no wildcards in pattern', function (): void {
    expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))
        ->toBeEmpty();
});

test('matches handles special regex characters literally', function (): void {
    expect(WildcardMatcher::matches('order.*.placed', 'order.(test).placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.+.placed', 'order.+.placed'))
        ->toBeTrue();
});
