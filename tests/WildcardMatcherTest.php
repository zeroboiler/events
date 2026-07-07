<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\WildcardMatcher;

test('matches exact event name', function (): void {
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.placed', 'order.shipped'))
        ->toBeFalse();
});

test('matches single wildcard', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.*', 'order.shipped'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.*', 'order.'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.*', 'payment.placed'))
        ->toBeFalse();
});

test('matches multiple wildcards', function (): void {
    expect(WildcardMatcher::matches('user.*.created', 'user.profile.created'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('user.*.created', 'user.settings.created'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('user.*.created', 'user.profile.updated'))
        ->toBeFalse();
});

test('matches wildcards in middle', function (): void {
    expect(WildcardMatcher::matches('api.*.v1.*', 'api.users.v1.index'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('api.*.v1.*', 'api.orders.v1.store'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('api.*.v1.*', 'web.users.v1.index'))
        ->toBeFalse();
});

test('matches leading wildcard', function (): void {
    expect(WildcardMatcher::matches('*.placed', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*.placed', 'invoice.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*.placed', 'order.shipped'))
        ->toBeFalse();
});

test('matches trailing wildcard', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.*', 'order.shipped'))
        ->toBeTrue();
});

test('does not match partial segments', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))
        ->toBeFalse()
        ->and(WildcardMatcher::matches('*.placed', 'order.placed.extra'))
        ->toBeFalse();
});

test('finds matching patterns from array', function (): void {
    $patterns = ['order.*', 'payment.*', 'invoice.created'];
    $matching = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($matching)->toBe(['order.*']);
});

test('finds multiple matching patterns', function (): void {
    $patterns = ['order.*', '*.placed', 'order.placed'];
    $matching = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($matching)->toBe(['order.*', '*.placed', 'order.placed']);
});

test('extracts wildcards from matched pattern', function (): void {
    $wildcards = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

    expect($wildcards)->toBe(['profile']);
});

test('extracts multiple wildcards from matched pattern', function (): void {
    $wildcards = WildcardMatcher::extractWildcards('*.users.*', 'admin.users.index');

    expect($wildcards)->toBe(['admin', 'index']);
});

test('extracts wildcards returns empty array on mismatch', function (): void {
    $wildcards = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.updated');

    expect($wildcards)->toBe([]);
});

test('extracts wildcards with deep nesting', function (): void {
    $wildcards = WildcardMatcher::extractWildcards('api.*.v1.*.store', 'api.products.v1.admin.store');

    expect($wildcards)->toBe(['products', 'admin']);
});

test('handles empty pattern', function (): void {
    expect(WildcardMatcher::matches('', 'order.placed'))
        ->toBeFalse();
});

test('handles empty event', function (): void {
    expect(WildcardMatcher::matches('order.*', ''))
        ->toBeFalse();
});

test('wildcard only matches everything', function (): void {
    expect(WildcardMatcher::matches('*', 'anything'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('*', '0'))
        ->toBeTrue();
});

test('wildcard pattern matches event named "0"', function (): void {
    // BUG-5 R33: "0" was incorrectly rejected for catch-all "*" pattern.
    // An event named "0" is unusual but valid and should match "*".
    expect(WildcardMatcher::matches('*', '0'))->toBeTrue();
});

test('wildcard pattern still rejects empty string', function (): void {
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
});

test('extracts single wildcard from wildcard only pattern', function (): void {
    $wildcards = WildcardMatcher::extractWildcards('*', 'anything');

    expect($wildcards)->toBe(['anything']);
});

test('handles dots in wildcard segments', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.*', 'order.placed.shipped'))
        ->toBeFalse();
});

test('multiple consecutive wildcards work as expected', function (): void {
    expect(WildcardMatcher::matches('a.*.*.b', 'a.x.y.b'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('a.*.*.b', 'a.x.b'))
        ->toBeFalse();
});

test('double wildcard matches across segments', function (): void {
    // BUG-3 R41: ** should match across dot boundaries
    expect(WildcardMatcher::matches('order.**', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.**', 'order.placed.extra'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.**', 'order.placed.shipped.via.email'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('order.**', 'order'))
        ->toBeFalse()
        ->and(WildcardMatcher::matches('order.**', 'payment.placed'))
        ->toBeFalse();
});

test('double wildcard at start matches across segments', function (): void {
    expect(WildcardMatcher::matches('**.placed', 'order.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('**.placed', 'order.nested.placed'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('**.placed', 'placed'))
        ->toBeFalse();
});

test('double wildcard in middle matches across segments', function (): void {
    expect(WildcardMatcher::matches('api.**.store', 'api.v1.users.store'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('api.**.store', 'api.store'))
        ->toBeFalse()
        ->and(WildcardMatcher::matches('api.**.store', 'api.users.v1.admin.store'))
        ->toBeTrue();
});

test('double wildcard only matches everything', function (): void {
    expect(WildcardMatcher::matches('**', 'anything'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('**', 'order.placed.nested'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('**', ''))
        ->toBeFalse();
});

test('mixed single and double wildcards work together', function (): void {
    // user.*.created matches single segment, user.**.created matches multi
    expect(WildcardMatcher::matches('user.*.**', 'user.profile.created'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('user.*.**', 'user.profile.created.nested'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('**.*.end', 'a.b.c.end'))
        ->toBeTrue()
        ->and(WildcardMatcher::matches('**.*.end', 'a.end'))
        ->toBeFalse();
});
