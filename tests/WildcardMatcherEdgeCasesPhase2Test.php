<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\WildcardMatcher;

/**
 * WildcardMatcher edge cases — special regex characters in event names,
 * empty patterns, consecutive wildcards, Unicode, and boundary conditions.
 *
 * These tests run without TestCase (no Laravel bootstrap needed).
 */
describe('WildcardMatcher edge cases', function (): void {
    // ─── Empty patterns ────────────────────────────────────────────────

    test('empty pattern does not match empty event', function (): void {
        expect(WildcardMatcher::matches('', ''))->toBeFalse();
    });

    test('empty pattern does not match non-empty event', function (): void {
        expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
    });

    test('non-empty pattern does not match empty event', function (): void {
        expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
    });

    test('catch-all * does not match empty event', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    });

    test('catch-all ** does not match empty event', function (): void {
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    // ─── Special characters in event names ────────────────────────────────

    test('pattern matches event with dots', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    });

    test('pattern matches event with dashes', function (): void {
        expect(WildcardMatcher::matches('user-created', 'user-created'))->toBeTrue();
    });

    test('pattern with * matches event with special chars', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.created-v2'))->toBeTrue();
    });

    test('event name with regex special chars is matched literally', function (): void {
        // Event names like 'order.(created)' should NOT trigger regex issues
        expect(WildcardMatcher::matches('order.(created)', 'order.(created)'))->toBeTrue();
    });

    test('event name with brackets is matched literally', function (): void {
        expect(WildcardMatcher::matches('user[admin]', 'user[admin]'))->toBeTrue();
    });

    test('event name with plus sign is matched literally', function (): void {
        expect(WildcardMatcher::matches('order+item', 'order+item'))->toBeTrue();
    });

    test('event name with dollar sign is matched literally', function (): void {
        expect(WildcardMatcher::matches('payment$100', 'payment$100'))->toBeTrue();
    });

    test('event name with caret is matched literally', function (): void {
        expect(WildcardMatcher::matches('log^info', 'log^info'))->toBeTrue();
    });

    test('event name with pipe is matched literally', function (): void {
        expect(WildcardMatcher::matches('a|b', 'a|b'))->toBeTrue();
    });

    // ─── Consecutive wildcards ─────────────────────────────────────────

    test('triple star is treated as single cross-segment wildcard', function (): void {
        // *** → after replacement: first ** → .*, remaining \* → [^.]*
        expect(WildcardMatcher::matches('order.***', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.***', 'order.a.b'))->toBeTrue();
    });

    // ─── Multi-segment deep events ──────────────────────────────────────

    test('** matches deeply nested events', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.a.b.c.d'))->toBeTrue();
    });

    test('single * does not match across segments', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.a.b'))->toBeFalse();
    });

    // ─── Exact match vs wildcard ───────────────────────────────────────

    test('exact event name matches exactly', function (): void {
        expect(WildcardMatcher::matches('user.created', 'user.created'))->toBeTrue();
    });

    test('exact event name does not match partial', function (): void {
        expect(WildcardMatcher::matches('user.created', 'user.created.extra'))->toBeFalse();
        expect(WildcardMatcher::matches('user.created', 'prefix.user.created'))->toBeFalse();
    });

    // ─── findMatchingPatterns ───────────────────────────────────────────

    test('findMatchingPatterns returns only matching patterns', function (): void {
        $patterns = ['order.*', 'user.created', 'payment.**', 'test.event'];
        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matches)->toContain('order.*');
        expect($matches)->not->toContain('user.created');
        expect($matches)->not->toContain('test.event');
    });

    test('findMatchingPatterns returns empty array for no matches', function (): void {
        $matches = WildcardMatcher::findMatchingPatterns(['order.*'], 'user.created');
        expect($matches)->toBe([]);
    });

    test('findMatchingPatterns preserves order', function (): void {
        $patterns = ['user.*', 'order.*', '*.created'];
        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.created');

        expect($matches)->toEqual(['order.*', '*.created']);
    });

    // ─── extractWildcards ───────────────────────────────────────────────

    test('extractWildcards returns empty for no wildcards in pattern', function (): void {
        expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))->toBe([]);
    });

    test('extractWildcards extracts single wildcard', function (): void {
        expect(WildcardMatcher::extractWildcards('order.*.done', 'order.item.done'))
            ->toBe(['item']);
    });

    test('extractWildcards extracts multiple wildcards', function (): void {
        expect(WildcardMatcher::extractWildcards('*.order.*', 'user.order.created'))
            ->toBe(['user', 'created']);
    });

    test('extractWildcards returns empty for ** pattern', function (): void {
        expect(WildcardMatcher::extractWildcards('order.**', 'order.a.b.c'))->toBe([]);
    });

    test('extractWildcards returns empty for segment count mismatch', function (): void {
        expect(WildcardMatcher::extractWildcards('order.*', 'order.a.b'))->toBe([]);
    });

    // ─── Event names with numeric segments ──────────────────────────────

    test('numeric segments match correctly', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.123'))->toBeTrue();
    });

    test('zero string event matches', function (): void {
        expect(WildcardMatcher::matches('0', '0'))->toBeTrue();
    });
});
