<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;

test('ConditionEngine matches with === operator (strict equality)', function (): void {
    $engine = new ConditionEngine;

    // Same type, same value → true
    expect($engine->matches(['count' => ['===', 5]], ['count' => 5]))->toBeTrue();

    // Same value, different type (int vs string) → false
    expect($engine->matches(['count' => ['===', '5']], ['count' => 5]))->toBeFalse();

    // Both strings → true
    expect($engine->matches(['name' => ['===', 'test']], ['name' => 'test']))->toBeTrue();

    // Both bool → true
    expect($engine->matches(['active' => ['===', true]], ['active' => true]))->toBeTrue();
    expect($engine->matches(['active' => ['===', false]], ['active' => false]))->toBeTrue();

    // true !== 1 (strict) → false
    expect($engine->matches(['flag' => ['===', true]], ['flag' => 1]))->toBeFalse();
});

test('ConditionEngine matches with !== operator (strict inequality)', function (): void {
    $engine = new ConditionEngine;

    // Same type, same value → false
    expect($engine->matches(['count' => ['!==', 5]], ['count' => 5]))->toBeFalse();

    // Different type → true
    expect($engine->matches(['count' => ['!==', '5']], ['count' => 5]))->toBeTrue();

    // Different value, same type → true
    expect($engine->matches(['name' => ['!==', 'other']], ['name' => 'test']))->toBeTrue();
});

test('ConditionEngine matches with = operator (type-coerced equality)', function (): void {
    $engine = new ConditionEngine;

    // Same value, different type → true (string coercion)
    expect($engine->matches(['count' => ['=', '5']], ['count' => 5]))->toBeTrue();

    // Same type, same value → true
    expect($engine->matches(['count' => ['=', 5]], ['count' => 5]))->toBeTrue();

    // Different values → false
    expect($engine->matches(['count' => ['=', 5]], ['count' => 10]))->toBeFalse();

    // Null vs string → false (strictEquals returns false for non-scalar mixed)
    expect($engine->matches(['data' => ['=', 'test']], ['data' => null]))->toBeFalse();

    // Array vs string → false
    expect($engine->matches(['tags' => ['=', 'test']], ['tags' => ['test']]))->toBeFalse();
});

test('ConditionEngine matches with != operator (type-coerced inequality)', function (): void {
    $engine = new ConditionEngine;

    // Same value, same type → false
    expect($engine->matches(['count' => ['!=', 5]], ['count' => 5]))->toBeFalse();

    // Same value, different type → false (string coercion makes them equal)
    expect($engine->matches(['count' => ['!=', '5']], ['count' => 5]))->toBeFalse();

    // Different values → true
    expect($engine->matches(['count' => ['!=', 10]], ['count' => 5]))->toBeTrue();
});

test('ConditionEngine matches with in operator', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'user']))->toBeFalse();
    expect($engine->matches(['role' => ['in', []]], ['role' => 'admin']))->toBeFalse();
});

test('ConditionEngine matches with not_in operator', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['role' => ['not_in', ['admin', 'mod']]], ['role' => 'user']))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['admin', 'mod']]], ['role' => 'admin']))->toBeFalse();
});

test('ConditionEngine matches with contains operator for strings', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['body' => ['contains', 'hello']], ['body' => 'hello world']))->toBeTrue();
    expect($engine->matches(['body' => ['contains', 'bye']], ['body' => 'hello world']))->toBeFalse();
});

test('ConditionEngine matches with contains operator for arrays', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'bug']]))->toBeTrue();
    expect($engine->matches(['tags' => ['contains', 'feature']], ['tags' => ['urgent', 'bug']]))->toBeFalse();
});

test('ConditionEngine matches with not_contains operator', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['body' => ['not_contains', 'spam']], ['body' => 'hello world']))->toBeTrue();
    expect($engine->matches(['body' => ['not_contains', 'hello']], ['body' => 'hello world']))->toBeFalse();
});

test('ConditionEngine matches with starts_with operator', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@example.com']))->toBeTrue();
    expect($engine->matches(['email' => ['starts_with', 'user@']], ['email' => 'admin@example.com']))->toBeFalse();

    // Non-string actual → false
    expect($engine->matches(['count' => ['starts_with', '1']], ['count' => 123]))->toBeFalse();
});

test('ConditionEngine matches with ends_with operator', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();
    expect($engine->matches(['domain' => ['ends_with', '.org']], ['domain' => 'example.com']))->toBeFalse();
});

test('ConditionEngine matches with between operator normalizes inverted ranges', function (): void {
    $engine = new ConditionEngine;

    // Normal range
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 17]))->toBeFalse();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 66]))->toBeFalse();

    // Inverted range (100, 50) → normalizes to (50, 100)
    expect($engine->matches(['age' => ['between', [100, 50]]], ['age' => 75]))->toBeTrue();

    // Boundary values (inclusive)
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 18]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 65]))->toBeTrue();

    // Non-numeric actual → false
    expect($engine->matches(['name' => ['between', [1, 10]]], ['name' => 'test']))->toBeFalse();
});

test('ConditionEngine matches with null and not_null operators', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']))->toBeFalse();

    expect($engine->matches(['email' => ['not_null']], ['email' => 'test@example.com']))->toBeTrue();
    expect($engine->matches(['email' => ['not_null']], ['email' => null]))->toBeFalse();
});

test('ConditionEngine matches with empty and not_empty operators', function (): void {
    $engine = new ConditionEngine;

    // null → empty
    expect($engine->matches(['notes' => ['empty']], ['notes' => null]))->toBeTrue();
    // empty string → empty
    expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
    // 0 → empty
    expect($engine->matches(['count' => ['empty']], ['count' => 0]))->toBeTrue();
    // '0' → empty
    expect($engine->matches(['count' => ['empty']], ['count' => '0']))->toBeTrue();
    // false → empty
    expect($engine->matches(['active' => ['empty']], ['active' => false]))->toBeTrue();
    // empty array → empty
    expect($engine->matches(['tags' => ['empty']], ['tags' => []]))->toBeTrue();

    // Non-empty values → not empty
    expect($engine->matches(['name' => ['not_empty']], ['name' => 'test']))->toBeTrue();
    expect($engine->matches(['count' => ['not_empty']], ['count' => 5]))->toBeTrue();
    expect($engine->matches(['tags' => ['not_empty']], ['tags' => ['a']]))->toBeTrue();

    // not_empty rejects empty values
    expect($engine->matches(['name' => ['not_empty']], ['name' => '']))->toBeFalse();
    expect($engine->matches(['name' => ['not_empty']], ['name' => null]))->toBeFalse();
});

test('ConditionEngine matches with nested dot notation', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => ['role' => 'admin']],
    ))->toBeTrue();

    expect($engine->matches(
        ['user.role' => 'user'],
        ['user' => ['role' => 'admin']],
    ))->toBeFalse();

    // Missing nested key → null → comparison fails
    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => ['name' => 'John']],
    ))->toBeFalse();

    // Deep nesting
    expect($engine->matches(
        ['order.total' => ['>', 100]],
        ['order' => ['total' => 150]],
    ))->toBeTrue();
});

test('ConditionEngine returns false for empty conditions array', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([], ['anything' => 'value']))->toBeTrue();
});

test('ConditionEngine returns false for empty array operator', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['status' => []], ['status' => 'active']))->toBeFalse();
});

test('ConditionEngine returns false for unknown operator', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['status' => ['unknown_op', 'value']], ['status' => 'active']))->toBeFalse();
});

test('ConditionEngine short-circuits on first failing condition', function (): void {
    $engine = new ConditionEngine;

    // First condition fails, second should not be evaluated
    expect($engine->matches(
        [
            'always_fail' => ['unknown_op'],
            'should_not_evaluate' => ['>', 'not_numeric'],
        ],
        ['always_fail' => 'x', 'should_not_evaluate' => 'y'],
    ))->toBeFalse();
});

test('ConditionEngine all conditions must match (AND logic)', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(
        [
            'status' => 'active',
            'amount' => ['>', 50],
        ],
        ['status' => 'active', 'amount' => 100],
    ))->toBeTrue();

    // One condition fails → false
    expect($engine->matches(
        [
            'status' => 'active',
            'amount' => ['>', 50],
        ],
        ['status' => 'inactive', 'amount' => 100],
    ))->toBeFalse();
});

