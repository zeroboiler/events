<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use ZeroBoiler\Events\Contracts\ConditionEngineContract;

final class ConditionEngine implements ConditionEngineContract
{
    /**
     * Maximum regex length for 'matches' operator to prevent ReDoS.
     */
    private const MAX_REGEX_LENGTH = 500;

    /**
     * Evaluate conditions against a payload.
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $payload
     */
    public function matches(array $conditions, array $payload): bool
    {
        foreach ($conditions as $field => $expected) {
            if (! $this->evaluateCondition($field, $expected, $payload)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single condition.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function evaluateCondition(string $field, mixed $expected, array $payload): bool
    {
        $actual = $this->getNestedValue($payload, $field);

        // Array operator syntax: [">", 100] or ["null"]
        if (is_array($expected)) {
            if ($expected === []) {
                return false;
            }

            $operator = is_string($expected[0]) ? $expected[0] : '';
            $value = $expected[1] ?? null;

            // Guard against null actual values for comparison operators
            return match ($operator) {
                '>' => $actual !== null && is_numeric($actual) && $actual > $value,
                '>=' => $actual !== null && is_numeric($actual) && $actual >= $value,
                '<' => $actual !== null && is_numeric($actual) && $actual < $value,
                '<=' => $actual !== null && is_numeric($actual) && $actual <= $value,
                '=' => $this->strictEquals($actual, $value),
                '===' => $actual === $value,
                '!=' => ! $this->strictEquals($actual, $value),
                '!==' => $actual !== $value,
                'in' => $value !== null && in_array($actual, (array) $value, true),
                'not_in' => $value !== null && ! in_array($actual, (array) $value, true),
                'contains' => $this->contains($actual, $value),
                'not_contains' => ! $this->contains($actual, $value),
                'between' => $actual !== null && $this->between($actual, $value),
                'null' => $actual === null,
                'not_null' => $actual !== null,
                'empty' => empty($actual),
                'not_empty' => ! empty($actual),
                'starts_with' => is_string($actual) && is_string($value) && str_starts_with($actual, $value),
                'ends_with' => is_string($actual) && is_string($value) && str_ends_with($actual, $value),
                'matches' => is_string($actual) && is_string($value) && $this->safeRegexMatch($value, $actual),
                default => $this->strictEquals($actual, $expected),
            };
        }

        // Simple equality check
        return $this->strictEquals($actual, $expected);
    }

    /**
     * Type-safe equality comparison.
     *
     * Uses strict comparison when types are compatible, falls back to
     * string comparison for scalar values of different types.
     * Returns false for non-scalar mixed types (e.g., array vs string).
     */
    private function strictEquals(mixed $actual, mixed $expected): bool
    {
        if (get_debug_type($actual) === get_debug_type($expected)) {
            return $actual === $expected;
        }

        // Different types: compare as strings only if both are scalar
        if (is_scalar($actual) && is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return false;
    }

    /**
     * Safely match a regex pattern against a subject.
     *
     * Prevents ReDoS by:
     * - Limiting pattern length to 500 characters
     * - Limiting PCRE backtrack limit to 1000
     * - Rejecting common catastrophic backtracking patterns (nested quantifiers)
     * - Returning false on any error
     */
    protected function safeRegexMatch(string $pattern, string $subject): bool
    {
        if (strlen($pattern) > self::MAX_REGEX_LENGTH) {
            return false;
        }

        // Reject common catastrophic backtracking patterns:
        // nested quantifiers like (a+)+ or (a*)*  etc.
        if (preg_match('/\([^)]*[+*?][^)]*\)[+*{]/', $pattern)) {
            return false;
        }

        $previousBacktrack = @ini_set('pcre.backtrack_limit', '1000');

        try {
            $result = @preg_match($pattern, $subject);

            return $result === 1;
        } finally {
            if ($previousBacktrack !== false) {
                @ini_set('pcre.backtrack_limit', $previousBacktrack);
            }
        }
    }

    /**
     * Get a nested value from an array using dot notation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function getNestedValue(array $data, string $key): mixed
    {
        $keys = explode('.', $key);
        $current = $data;

        foreach ($keys as $k) {
            if (! is_array($current) || ! array_key_exists($k, $current)) {
                return null;
            }

            $current = $current[$k];
        }

        return $current;
    }

    /**
     * Check if actual contains value (for strings and arrays).
     */
    protected function contains(mixed $actual, mixed $value): bool
    {
        if (is_array($actual)) {
            return in_array($value, $actual, true);
        }

        if (! is_string($actual) || ! is_string($value)) {
            return false;
        }

        return str_contains($actual, $value);
    }

    /**
     * Check if actual is between min and max (inclusive).
     */
    protected function between(mixed $actual, mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 2) {
            return false;
        }

        if (! is_numeric($actual)) {
            return false;
        }

        // Auto-normalize inverted ranges (e.g., [100, 50] → [50, 100])
        $min = min($value[0], $value[1]);
        $max = max($value[0], $value[1]);

        return $actual >= $min && $actual <= $max;
    }
}
