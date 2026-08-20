<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\WildcardMatcher;

test('WildcardMatcher rejects empty event for non-catch-all patterns', function (): void {
    expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('order.**', ''))->toBeFalse();
});

test('WildcardMatcher catch-all * matches any non-empty event', function (): void {
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', 'a.b.c.d'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
});

test('WildcardMatcher catch-all ** matches any non-empty event', function (): void {
    expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('**', 'a.b.c.d'))->toBeTrue();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
});

test('WildcardMatcher single-segment * does not match across dots', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    expect(WildcardMatcher::matches('order.*', 'order'))->toBeFalse();
});

test('WildcardMatcher double-segment ** matches across dots', function (): void {
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.a.b.c.d'))->toBeTrue();
});

test('WildcardMatcher multiple single-segment wildcards', function (): void {
    expect(WildcardMatcher::matches('*.*.created', 'user.profile.created'))->toBeTrue();
    expect(WildcardMatcher::matches('*.*.created', 'user.profile.updated'))->toBeFalse();
    expect(WildcardMatcher::matches('*.*.created', 'user.profile.action.created'))->toBeFalse();
});

test('WildcardMatcher exact match without wildcards', function (): void {
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    expect(WildcardMatcher::matches('order.placed', 'order.placed.extra'))->toBeFalse();
});

test('WildcardMatcher escapes regex special characters', function (): void {
    expect(WildcardMatcher::matches('user.login', 'user.login'))->toBeTrue();
    expect(WildcardMatcher::matches('user.login', 'userXlogin'))->toBeFalse();
});

test('WildcardMatcher findMatchingPatterns returns only matching patterns', function (): void {
    $patterns = ['order.*', 'user.*', '*.placed', 'order.placed'];

    $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($result)->toContain('order.*')
        ->toContain('*.placed')
        ->toContain('order.placed')
        ->not->toContain('user.*');
});

test('WildcardMatcher findMatchingPatterns returns empty for no matches', function (): void {
    $patterns = ['user.*', 'invoice.*'];

    $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($result)->toBeEmpty();
});

test('WildcardMatcher extractWildcards returns extracted segments', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

    expect($result)->toBe(['profile']);
});

test('WildcardMatcher extractWildcards returns empty for ** patterns', function (): void {
    $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

    expect($result)->toBeEmpty();
});

test('WildcardMatcher extractWildcards returns empty when segments dont align', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*', 'user.profile.created');

    expect($result)->toBeEmpty();
});

test('WildcardMatcher extractWildcards returns empty when event does not match pattern', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*.deleted', 'user.profile.created');

    expect($result)->toBeEmpty();
});

test('WildcardMatcher extractWildcards with multiple wildcards', function (): void {
    $result = WildcardMatcher::extractWildcards('*.*.created', 'user.profile.created');

    expect($result)->toBe(['user', 'profile']);
});

test('WildcardMatcher handles pattern with trailing dot', function (): void {
    expect(WildcardMatcher::matches('order.', 'order.'))->toBeTrue();
    expect(WildcardMatcher::matches('order.', 'order.placed'))->toBeFalse();
});

test('WildcardMatcher pattern with leading dot', function (): void {
    expect(WildcardMatcher::matches('.placed', '.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('.placed', 'order.placed'))->toBeFalse();
});

test('WildcardMatcher handles backslash in event name', function (): void {
    // Backslash is a regex escape character — should be treated literally
    expect(WildcardMatcher::matches('user\\login', 'user\\login'))->toBeTrue();
    expect(WildcardMatcher::matches('user\\login', 'userXlogin'))->toBeFalse();
});

