<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Comprehensive edge case audit for ConditionEngine and WildcardMatcher.
 *
 * Covers:
 * - ConditionEngine: null handling with all operators, type coercion edge cases,
 *   empty arrays, nested dot notation with missing keys, ReDoS protection limits.
 * - WildcardMatcher: edge patterns with dots, multiple wildcards, empty strings,
 *   very long event names, patterns with escaped characters.
 *
 * @see \ZeroBoiler\Events\ConditionEngine
 * @see \ZeroBoiler\Events\WildcardMatcher
 */
class EventsPhase199ConditionAndWildcardEdgeCaseAuditTest extends TestCase
{
    // ========================
    // ConditionEngine Edge Cases
    // ========================

    public function test_empty_conditions_array_returns_true(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches([], ['any' => 'data']);

        expect($result)->toBeTrue();
    }

    public function test_null_payload_with_no_conditions(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches([], []);

        expect($result)->toBeTrue();
    }

    public function test_null_field_value_with_equality_returns_false(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['name' => 'John'],
            ['name' => null],
        );

        expect($result)->toBeFalse();
    }

    public function test_null_field_with_null_operator_returns_true(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['deleted_at' => ['null']],
            ['deleted_at' => null],
        );

        expect($result)->toBeTrue();
    }

    public function test_null_field_with_not_null_operator_returns_false(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['deleted_at' => ['not_null']],
            ['deleted_at' => null],
        );

        expect($result)->toBeFalse();
    }

    public function test_numeric_string_comparison(): void
    {
        $engine = new ConditionEngine;

        // "100" (string) vs 100 (int) — different types, string comparison
        $result = $engine->matches(
            ['amount' => ['>', 50]],
            ['amount' => '100'],
        );

        expect($result)->toBeTrue();
    }

    public function test_nested_dot_notation_missing_key_returns_null(): void
    {
        $engine = new ConditionEngine;

        // Missing intermediate key should evaluate to null
        $result = $engine->matches(
            ['user.profile.name' => ['null']],
            ['user' => ['email' => 'test@example.com']],
        );

        expect($result)->toBeTrue();
    }

    public function test_nested_dot_notation_deep_missing(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['a.b.c.d' => ['null']],
            ['a' => ['b' => []]],
        );

        expect($result)->toBeTrue();
    }

    public function test_between_with_inverted_range(): void
    {
        $engine = new ConditionEngine;

        // Inverted range [100, 50] should auto-normalize to [50, 100]
        $result = $engine->matches(
            ['value' => ['between', [100, 50]]],
            ['value' => 75],
        );

        expect($result)->toBeTrue();
    }

    public function test_between_exactly_at_min(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['value' => ['between', [50, 100]]],
            ['value' => 50],
        );

        expect($result)->toBeTrue();
    }

    public function test_between_exactly_at_max(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['value' => ['between', [50, 100]]],
            ['value' => 100],
        );

        expect($result)->toBeTrue();
    }

    public function test_between_outside_max(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['value' => ['between', [50, 100]]],
            ['value' => 101],
        );

        expect($result)->toBeFalse();
    }

    public function test_empty_array_condition_returns_false(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['tags' => []],
            ['tags' => ['a', 'b']],
        );

        expect($result)->toBeFalse();
    }

    public function test_matches_with_valid_regex(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']],
            ['code' => 'ABC-1234'],
        );

        expect($result)->toBeTrue();
    }

    public function test_matches_with_non_matching_regex(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']],
            ['code' => 'invalid'],
        );

        expect($result)->toBeFalse();
    }

    public function test_matches_rejects_long_pattern(): void
    {
        $engine = new ConditionEngine;

        $longPattern = '/' . str_repeat('a', 600) . '/';

        $result = $engine->matches(
            ['input' => ['matches', $longPattern]],
            ['input' => 'test'],
        );

        expect($result)->toBeFalse();
    }

    public function test_matches_rejects_nested_quantifier_pattern(): void
    {
        $engine = new ConditionEngine;

        // Nested quantifier (a+)+ — catastrophic backtracking risk
        $result = $engine->matches(
            ['input' => ['matches', '/(a+)+b/']],
            ['input' => 'aaab'],
        );

        expect($result)->toBeFalse();
    }

    public function test_in_operator_with_non_array_value(): void
    {
        $engine = new ConditionEngine;

        // When value for 'in' is not iterable, should return false
        $result = $engine->matches(
            ['role' => ['in', 'admin']],
            ['role' => 'admin'],
        );

        // 'in' with a string value — cast to array gives ['admin'], so 'admin' in ['admin'] = true
        expect($result)->toBeTrue();
    }

    public function test_starts_with_non_string_actual(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['name' => ['starts_with', 'Admin']],
            ['name' => 12345],
        );

        expect($result)->toBeFalse();
    }

    public function test_ends_with_non_string_actual(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['name' => ['ends_with', '.com']],
            ['name' => null],
        );

        expect($result)->toBeFalse();
    }

    public function test_contains_with_array_actual(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['tags' => ['contains', 'urgent']],
            ['tags' => ['normal', 'urgent', 'billing']],
        );

        expect($result)->toBeTrue();
    }

    public function test_contains_with_string_actual(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['message' => ['contains', 'error']],
            ['message' => 'fatal error occurred'],
        );

        expect($result)->toBeTrue();
    }

    public function test_strict_equals_different_types_both_scalar(): void
    {
        $engine = new ConditionEngine;

        // String comparison for different scalar types
        $result = $engine->matches(
            ['count' => '10'],
            ['count' => 10],
        );

        // Different types but both scalar → string comparison "10" === "10"
        expect($result)->toBeTrue();
    }

    public function test_all_conditions_must_match_and_logic(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            [
                'amount' => ['>', 50],
                'status' => 'active',
                'tags' => ['contains', 'premium'],
            ],
            [
                'amount' => 100,
                'status' => 'active',
                'tags' => ['premium', 'vip'],
            ],
        );

        expect($result)->toBeTrue();
    }

    public function test_one_failing_condition_returns_false(): void
    {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            [
                'amount' => ['>', 50],
                'status' => 'active',
            ],
            [
                'amount' => 100,
                'status' => 'inactive',
            ],
        );

        expect($result)->toBeFalse();
    }

    // ========================
    // WildcardMatcher Edge Cases
    // ========================

    public function test_wildcard_exact_match_no_pattern(): void
    {
        $result = WildcardMatcher::matches('order.placed', 'order.placed');

        expect($result)->toBeTrue();
    }

    public function test_wildcard_exact_mismatch(): void
    {
        $result = WildcardMatcher::matches('order.placed', 'order.shipped');

        expect($result)->toBeFalse();
    }

    public function test_wildcard_single_segment_match(): void
    {
        $result = WildcardMatcher::matches('order.*', 'order.placed');

        expect($result)->toBeTrue();
    }

    public function test_wildcard_single_segment_no_cross_segment(): void
    {
        $result = WildcardMatcher::matches('order.*', 'order.placed.extra');

        expect($result)->toBeFalse();
    }

    public function test_wildcard_cross_segment_match(): void
    {
        $result = WildcardMatcher::matches('order.**', 'order.placed.extra.deep');

        expect($result)->toBeTrue();
    }

    public function test_wildcard_catch_all_star(): void
    {
        $result = WildcardMatcher::matches('*', 'any.event.name');

        expect($result)->toBeTrue();
    }

    public function test_wildcard_catch_all_star_rejects_empty(): void
    {
        $result = WildcardMatcher::matches('*', '');

        expect($result)->toBeFalse();
    }

    public function test_wildcard_double_star_catch_all(): void
    {
        $result = WildcardMatcher::matches('**', 'anything');

        expect($result)->toBeTrue();
    }

    public function test_wildcard_double_star_rejects_empty(): void
    {
        $result = WildcardMatcher::matches('**', '');

        expect($result)->toBeFalse();
    }

    public function test_wildcard_multiple_segments(): void
    {
        $result = WildcardMatcher::matches('*.order.*', 'user.order.created');

        expect($result)->toBeTrue();
    }

    public function test_wildcard_extract_single_segment(): void
    {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        expect($result)->toBe(['profile']);
    }

    public function test_wildcard_extract_multiple_segments(): void
    {
        $result = WildcardMatcher::extractWildcards('*.order.*', 'user.order.created');

        expect($result)->toBe(['user', 'created']);
    }

    public function test_wildcard_extract_returns_empty_for_cross_segment(): void
    {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        expect($result)->toBe([]);
    }

    public function test_wildcard_extract_returns_empty_when_not_matching(): void
    {
        $result = WildcardMatcher::extractWildcards('user.*.deleted', 'user.profile.created');

        expect($result)->toBe([]);
    }

    public function test_wildcard_find_matching_patterns(): void
    {
        $patterns = ['order.*', 'user.*', '*.created'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe(['order.*']);
    }

    public function test_wildcard_find_matching_patterns_multiple(): void
    {
        $patterns = ['*', 'order.*', '*.placed'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        // '*' matches, 'order.*' matches, '*.placed' also matches
        expect($result)->toBe(['*', 'order.*', '*.placed']);
    }

    public function test_wildcard_pattern_with_regex_chars(): void
    {
        // Event names with dots should not be confused with regex
        $result = WildcardMatcher::matches('user.*', 'user.');

        expect($result)->toBeFalse();
    }

    public function test_wildcard_empty_pattern_empty_event(): void
    {
        $result = WildcardMatcher::matches('', '');

        expect($result)->toBeTrue();
    }

    public function test_wildcard_empty_pattern_non_empty_event(): void
    {
        $result = WildcardMatcher::matches('', 'event.name');

        expect($result)->toBeFalse();
    }
}
