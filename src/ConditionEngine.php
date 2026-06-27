<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use ZeroBoiler\Events\Contracts\ConditionEngineContract;

class ConditionEngine implements ConditionEngineContract
{
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

        // Array operator syntax: ["field" => [">", 100]] or ["field" => ["null"]]
        if (is_array($expected)) {
            $operator = $expected[0];
            $value = $expected[1] ?? null;

            return match ($operator) {
                '>' => $actual > $value,
                '>=' => $actual >= $value,
                '<' => $actual < $value,
                '<=' => $actual <= $value,
                '=' => $actual == $value,
                '===' => $actual === $value,
                '!=' => $actual != $value,
                '!==' => $actual !== $value,
                'in' => in_array($actual, (array) $value, true),
                'not_in' => ! in_array($actual, (array) $value, true),
                'contains' => $this->contains($actual, $value),
                'not_contains' => ! $this->contains($actual, $value),
                'between' => $this->between($actual, $value),
                'null' => $actual === null,
                'not_null' => $actual !== null,
                'empty' => empty($actual),
                'not_empty' => ! empty($actual),
                'starts_with' => str_starts_with((string) $actual, (string) $value),
                'ends_with' => str_ends_with((string) $actual, (string) $value),
                'matches' => (bool) preg_match((string) $value, (string) $actual),
                default => $actual == $expected,
            };
        }

        // Simple equality check
        return $actual == $expected;
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

        return str_contains((string) $actual, (string) $value);
    }

    /**
     * Check if actual is between min and max (inclusive).
     */
    protected function between(mixed $actual, mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 2) {
            return false;
        }

        return $actual >= $value[0] && $actual <= $value[1];
    }
}
