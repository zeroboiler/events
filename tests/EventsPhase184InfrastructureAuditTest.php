<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 184 — Infrastructure audit: operator edge cases, sanitizer depth,
 * WildcardMatcher boundary conditions, and source file quality checks.
 */
final class EventsPhase184InfrastructureAuditTest extends TestCase
{
    // ── ConditionEngine: not_contains operator ──────────────────────────

    public function test_not_contains_returns_false_when_value_is_in_array(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['tags' => ['not_contains', 'urgent']],
            ['tags' => ['urgent', 'billing']],
        );

        $this->assertFalse($result);
    }

    public function test_not_contains_returns_true_when_value_is_not_in_array(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['tags' => ['not_contains', 'spam']],
            ['tags' => ['urgent', 'billing']],
        );

        $this->assertTrue($result);
    }

    public function test_not_contains_returns_true_when_actual_is_string_and_not_found(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['message' => ['not_contains', 'error']],
            ['message' => 'all systems operational'],
        );

        $this->assertTrue($result);
    }

    public function test_not_contains_returns_false_when_actual_is_string_and_found(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['message' => ['not_contains', 'error']],
            ['message' => 'critical error detected'],
        );

        $this->assertFalse($result);
    }

    public function test_not_contains_returns_true_for_non_string_non_array_actual(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['count' => ['not_contains', 'test']],
            ['count' => 42],
        );

        $this->assertTrue($result);
    }

    public function test_not_contains_empty_actual_array_returns_true(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['tags' => ['not_contains', 'x']],
            ['tags' => []],
        );

        $this->assertTrue($result);
    }

    // ── ConditionEngine: not_empty operator ──────────────────────────────

    public function test_not_empty_returns_true_for_non_empty_string(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['name' => ['not_empty']],
            ['name' => 'John'],
        );

        $this->assertTrue($result);
    }

    public function test_not_empty_returns_false_for_empty_string(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['name' => ['not_empty']],
            ['name' => ''],
        );

        $this->assertFalse($result);
    }

    public function test_not_empty_returns_true_for_non_empty_array(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['tags' => ['not_empty']],
            ['tags' => ['a']],
        );

        $this->assertTrue($result);
    }

    public function test_not_empty_returns_false_for_empty_array(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['tags' => ['not_empty']],
            ['tags' => []],
        );

        $this->assertFalse($result);
    }

    public function test_not_empty_returns_true_for_non_zero_int(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['count' => ['not_empty']],
            ['count' => 42],
        );

        $this->assertTrue($result);
    }

    public function test_not_empty_returns_false_for_zero(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['count' => ['not_empty']],
            ['count' => 0],
        );

        $this->assertFalse($result);
    }

    public function test_not_empty_returns_false_for_null(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['field' => ['not_empty']],
            ['field' => null],
        );

        $this->assertFalse($result);
    }

    // ── ConditionEngine: between with non-numeric actual ────────────────

    public function test_between_returns_false_for_non_numeric_actual(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['name' => ['between', [1, 10]]],
            ['name' => 'hello'],
        );

        $this->assertFalse($result);
    }

    public function test_between_returns_false_for_null_actual(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['field' => ['between', [1, 10]]],
            ['field' => null],
        );

        $this->assertFalse($result);
    }

    // ── ConditionEngine: between auto-normalize inverted range ──────────

    public function test_between_normalizes_inverted_range(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['value' => ['between', [100, 50]]],
            ['value' => 75],
        );

        $this->assertTrue($result);
    }

    public function test_between_excludes_out_of_range_with_inverted_input(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['value' => ['between', [100, 50]]],
            ['value' => 101],
        );

        $this->assertFalse($result);
    }

    // ── ConditionEngine: numeric operators null-safe ────────────────────

    public function test_greater_than_returns_false_when_actual_is_null(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['amount' => ['>', 0]],
            ['amount' => null],
        );

        $this->assertFalse($result);
    }

    public function test_greater_than_returns_false_when_value_is_null(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['amount' => ['>', null]],
            ['amount' => 100],
        );

        $this->assertFalse($result);
    }

    // ── WildcardMatcher: edge cases ────────────────────────────────────

    public function test_wildcard_matches_empty_pattern_against_empty_event(): void
    {
        // Empty pattern with empty event — no wildcard means exact match
        $result = WildcardMatcher::matches('', '');

        $this->assertTrue($result);
    }

    public function test_wildcard_no_match_empty_pattern_against_nonempty_event(): void
    {
        $result = WildcardMatcher::matches('', 'order.placed');

        $this->assertFalse($result);
    }

    public function test_wildcard_find_matching_patterns_empty_input(): void
    {
        $result = WildcardMatcher::findMatchingPatterns([], 'order.placed');

        $this->assertSame([], $result);
    }

    public function test_wildcard_find_matching_patterns_no_matches(): void
    {
        $result = WildcardMatcher::findMatchingPatterns(
            ['user.*', 'order.created'],
            'payment.processed',
        );

        $this->assertSame([], $result);
    }

    public function test_wildcard_find_matching_patterns_filters_correctly(): void
    {
        $result = WildcardMatcher::findMatchingPatterns(
            ['order.*', 'user.*', 'payment.*'],
            'order.placed',
        );

        $this->assertSame(['order.*'], $result);
    }

    public function test_extract_wildcards_returns_empty_for_non_wildcard_pattern(): void
    {
        $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');

        $this->assertSame([], $result);
    }

    public function test_extract_wildcards_returns_empty_for_double_star(): void
    {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.shipped');

        $this->assertSame([], $result);
    }

    // ── ConditionEngine: dot notation with missing keys ─────────────────

    public function test_dot_notation_missing_key_returns_null_and_fails_equality(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['user.profile.avatar' => 'photo.jpg'],
            ['user' => ['name' => 'John']],
        );

        $this->assertFalse($result);
    }

    public function test_dot_notation_null_field_matches_null_check(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['user.deleted_at' => ['null']],
            ['user' => ['name' => 'John']],
        );

        $this->assertTrue($result);
    }

    // ── ConditionEngine: starts_with / ends_with non-string ────────────

    public function test_starts_with_returns_false_for_non_string_actual(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['count' => ['starts_with', '1']],
            ['count' => 123],
        );

        $this->assertFalse($result);
    }

    public function test_ends_with_returns_false_for_non_string_actual(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['count' => ['ends_with', '3']],
            ['count' => 123],
        );

        $this->assertFalse($result);
    }

    // ── ConditionEngine: matches regex non-string ────────────────────────

    public function test_matches_regex_returns_false_for_non_string_actual(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches(
            ['code' => ['matches', '/^[A-Z]{3}$/']],
            ['code' => 123],
        );

        $this->assertFalse($result);
    }

    // ── ConditionEngine: empty conditions returns true ──────────────────

    public function test_empty_conditions_array_returns_true(): void
    {
        $engine = new ConditionEngine();

        $result = $engine->matches([], ['anything' => 'here']);

        $this->assertTrue($result);
    }

    // ── Source file quality checks ──────────────────────────────────────

    public function test_condition_engine_has_strict_types(): void
    {
        $content = file_get_contents(__DIR__.'/../src/ConditionEngine.php');

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function test_condition_engine_is_final(): void
    {
        $reflection = new \ReflectionClass(ConditionEngine::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function test_wildcard_matcher_is_readonly_final(): void
    {
        $reflection = new \ReflectionClass(WildcardMatcher::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_condition_engine_matches_has_override(): void
    {
        $method = new \ReflectionMethod(ConditionEngine::class, 'matches');

        $attributes = $method->getAttributes(\Override::class);

        $this->assertCount(1, $attributes);
    }

    public function test_condition_engine_strict_equals_is_pure(): void
    {
        $method = new \ReflectionMethod(ConditionEngine::class, 'strictEquals');

        $attributes = $method->getAttributes(\Attribute::class);
        $isPure = false;
        foreach ($attributes as $attr) {
            if ($attr->getArguments() === [2] || (isset($attr->getArguments()[0]) && $attr->getArguments()[0] === 2)) {
                // Attribute::TARGET_CLASS constant is not available in this context
                // Check named argument instead
            }
        }
        // Fallback: check for #[\Pure] attribute
        $doc = $method->getDocComment();
        $this->assertNotFalse($doc);
        $this->assertStringContainsString('#[\Pure]', $doc);
    }
}
