<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\WildcardMatcher;

/**
 * Additional edge-case tests for WildcardMatcher.
 *
 * Covers cross-segment + single-segment combinations, deep nesting,
 * empty segments, and boundary patterns.
 *
 * @see \ZeroBoiler\Events\WildcardMatcher
 *
 * @since 1.0.0
 */
final class WildcardMatcherEdgeCaseCombinationsTest extends TestCase
{
    public function test_cross_segment_then_single_segment(): void
    {
        // order.**.status should match order.placed.status
        expect(WildcardMatcher::matches('order.**.status', 'order.placed.status'))->toBeTrue();
        // And also order.placed.extra.status
        expect(WildcardMatcher::matches('order.**.status', 'order.placed.extra.status'))->toBeTrue();
    }

    public function test_single_segment_then_cross_segment(): void
    {
        // *.order.** should match any.prefix.order.extra
        expect(WildcardMatcher::matches('*.order.**', 'sales.order.processed'))->toBeTrue();
        expect(WildcardMatcher::matches('*.order.**', 'returns.order.refunded.extra'))->toBeTrue();
    }

    public function test_multiple_cross_segment_wildcards(): void
    {
        // **.order.** is redundant but should still match
        expect(WildcardMatcher::matches('**.order.**', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('**.order.**', 'prefix.order.placed.suffix'))->toBeTrue();
    }

    public function test_empty_segment_between_dots(): void
    {
        // Dotted event with empty-ish segments
        expect(WildcardMatcher::matches('order.*', 'order.'))->toBeFalse();
        expect(WildcardMatcher::matches('order.**', 'order.'))->toBeFalse();
    }

    public function test_exact_match_with_no_wildcard(): void
    {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        expect(WildcardMatcher::matches('order.placed', 'order.placed.extra'))->toBeFalse();
    }

    public function test_single_asterisk_catch_all(): void
    {
        expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
        expect(WildcardMatcher::matches('*', 'a.b.c.d'))->toBeTrue();
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    }

    public function test_double_asterisk_catch_all(): void
    {
        expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
        expect(WildcardMatcher::matches('**', 'a.b.c.d'))->toBeTrue();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    }

    public function test_wildcard_in_middle_of_pattern(): void
    {
        expect(WildcardMatcher::matches('order.*.completed', 'order.placed.completed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*.completed', 'order.shipped.completed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*.completed', 'order.placed.shipped'))->toBeFalse();
    }

    public function test_leading_dot_in_event(): void
    {
        // Events starting with dot
        expect(WildcardMatcher::matches('*.placed', '.placed'))->toBeFalse();
        expect(WildcardMatcher::matches('*', '.placed'))->toBeTrue();
    }

    public function test_trailing_dot_in_event(): void
    {
        expect(WildcardMatcher::matches('order.*', 'order.'))->toBeFalse();
        expect(WildcardMatcher::matches('order.**', 'order.'))->toBeFalse();
    }

    public function test_single_segment_wildcard_does_not_cross_segment(): void
    {
        expect(WildcardMatcher::matches('order.*', 'order.placed.shipped'))->toBeFalse();
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    }

    public function test_extract_wildcards_cross_segment_returns_empty(): void
    {
        // ** patterns always return empty array
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed');
        expect($result)->toBe([]);
    }

    public function test_extract_wildcards_multiple_single_segments(): void
    {
        $result = WildcardMatcher::extractWildcards('*.order.*', 'sales.order.created');
        expect($result)->toBe(['sales', 'created']);
    }

    public function test_extract_wildcards_mismatch_returns_empty(): void
    {
        $result = WildcardMatcher::extractWildcards('order.*.created', 'order.placed.shipped');
        expect($result)->toBe([]);
    }

    public function test_find_matching_patterns_filters_correctly(): void
    {
        $patterns = ['order.placed', 'order.*', 'payment.*', '*'];

        $matched = WildcardMatcher::findMatchingPatterns($patterns, 'order.shipped');

        expect($matched)->toContain('order.*');
        expect($matched)->toContain('*');
        expect($matched)->not->toContain('order.placed');
        expect($matched)->not->toContain('payment.*');
    }
}
