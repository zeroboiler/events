<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\WildcardMatcher;

// ─── WildcardMatcher::findMatchingPatterns ──────────────────────────────────

describe('WildcardMatcher::findMatchingPatterns', function (): void {
    it('returns all patterns that match the event', function (): void {
        $patterns = ['order.placed', 'order.*', 'payment.*', '*'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toContain('order.placed');
        expect($result)->toContain('order.*');
        expect($result)->toContain('*');
        expect($result)->not->toContain('payment.*');
    });

    it('returns empty array when no patterns match', function (): void {
        $patterns = ['order.*', 'payment.*'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'user.created');

        expect($result)->toBeEmpty();
    });

    it('handles empty patterns array', function (): void {
        $result = WildcardMatcher::findMatchingPatterns([], 'order.placed');

        expect($result)->toBeEmpty();
    });

    it('preserves order of matching patterns', function (): void {
        $patterns = ['*', 'order.*', 'order.placed'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe(['*', 'order.*', 'order.placed']);
    });

    it('handles cross-segment wildcards', function (): void {
        $patterns = ['order.**', 'user.*'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed.extra');

        expect($result)->toContain('order.**');
        expect($result)->not->toContain('user.*');
    });

    it('returns list type (sequential array)', function (): void {
        $patterns = ['order.*', '*'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        // array_values is applied, so keys should be sequential
        expect(array_is_list($result))->toBeTrue();
    });
});

// ─── WildcardMatcher::extractWildcards ──────────────────────────────────────

describe('WildcardMatcher::extractWildcards edge cases', function (): void {
    it('returns empty for empty pattern', function (): void {
        $result = WildcardMatcher::extractWildcards('', 'order.placed');

        expect($result)->toBeEmpty();
    });

    it('returns empty for empty event', function (): void {
        $result = WildcardMatcher::extractWildcards('order.*', '');

        expect($result)->toBeEmpty();
    });

    it('returns empty when segment count differs', function (): void {
        $result = WildcardMatcher::extractWildcards('order.*.test', 'order.placed');

        expect($result)->toBeEmpty();
    });

    it('extracts multiple wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('*.*.created', 'order.item.created');

        expect($result)->toBe(['order', 'item']);
    });

    it('returns empty array for non-matching pattern with wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('order.*', 'user.placed');

        expect($result)->toBeEmpty();
    });

    it('returns empty when ** is in pattern', function (): void {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        expect($result)->toBeEmpty();
    });
});

// ─── WildcardMatcher::matches additional edge cases ────────────────────────

describe('WildcardMatcher::matches Unicode', function (): void {
    it('matches unicode event names with wildcards', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.créé'))->toBeTrue();
    });

    it('handles exact match with unicode', function (): void {
        expect(WildcardMatcher::matches('user.äpfel', 'user.äpfel'))->toBeTrue();
        expect(WildcardMatcher::matches('user.äpfel', 'user.birne'))->toBeFalse();
    });
});

describe('WildcardMatcher::matches with special chars', function (): void {
    it('matches events with hyphens and underscores', function (): void {
        expect(WildcardMatcher::matches('order-*', 'order-placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order_*', 'order_placed'))->toBeTrue();
    });

    it('matches events with numbers', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.123'))->toBeTrue();
    });
});
