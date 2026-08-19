<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;

describe('ConditionEngine empty and edge-case operator handling', function (): void {
    test('matches returns true for empty conditions array (vacuous truth)', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches([], ['any' => 'data']))->toBeTrue();
        expect($engine->matches([], []))->toBeTrue();
    });

    test('empty array operator returns false (no operator to evaluate)', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
    });

    test('operator with non-string first element falls through to default (returns false)', function (): void {
        $engine = app(ConditionEngine::class);

        // Operator is not a string — should match 'default => false'
        expect($engine->matches(['field' => [123, 'value']], ['field' => 'value']))->toBeFalse();
    });

    test('between with non-array value returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['between', 'not-an-array']], ['amount' => 50]))->toBeFalse();
    });

    test('between with array but only one element returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['between', [50]]], ['amount' => 50]))->toBeFalse();
    });

    test('between with non-numeric actual returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['name' => ['between', [1, 10]]], ['name' => 'hello']))->toBeFalse();
    });

    test('between with non-numeric range values returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['between', ['a', 'z']]], ['amount' => 50]))->toBeFalse();
    });

    test('between auto-normalizes inverted range', function (): void {
        $engine = app(ConditionEngine::class);

        // Min > Max should be auto-normalized
        expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 75]))->toBeTrue();
        expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 49]))->toBeFalse();
        expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 101]))->toBeFalse();
    });

    test('comparison operators with non-numeric actual return false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['name' => ['>', 'abc']], ['name' => 'hello']))->toBeFalse();
        expect($engine->matches(['name' => ['<', 'abc']], ['name' => 'hello']))->toBeFalse();
    });

    test('comparison operators with non-numeric expected return false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['>', 'not-a-number']], ['amount' => 50]))->toBeFalse();
    });

    test('comparison operators with null actual return false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['>', 0]], ['other' => 'exists']))->toBeFalse();
    });

    test('comparison operators with null expected return false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['>', null]], ['amount' => 50]))->toBeFalse();
    });

    test('not_in operator returns false when value is null', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['role' => ['not_in', ['admin', 'mod']]], ['other' => 'exists']))->toBeFalse();
    });

    test('in operator returns false when value is null', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['other' => 'exists']))->toBeFalse();
    });

    test('dot notation returns null for non-existent nested key', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['user.profile.name' => 'John'], ['user' => ['email' => 'john@test.com']]))->toBeFalse();
    });

    test('dot notation evaluates nested field correctly', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin']],
        ))->toBeTrue();

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'user']],
        ))->toBeFalse();
    });

    test('strictEquals with same type returns true', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
    });

    test('strictEquals with different scalar types falls back to string comparison', function (): void {
        $engine = app(ConditionEngine::class);

        // int 42 vs string '42' — different types, both scalar, so compare as strings
        expect($engine->matches(['count' => '42'], ['count' => 42]))->toBeTrue();
    });

    test('strictEquals with array vs string returns false (non-scalar mismatch)', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['tags' => 'active'], ['tags' => ['active', 'new']]))->toBeFalse();
    });

    test('null operator matches when field is absent', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['deleted_at' => ['null']], ['name' => 'test']))->toBeTrue();
    });

    test('null operator does not match when field exists', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']))->toBeFalse();
    });

    test('not_null operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['email' => ['not_null']], ['email' => 'a@b.com']))->toBeTrue();
        expect($engine->matches(['email' => ['not_null']], ['name' => 'test']))->toBeFalse();
    });

    test('empty operator matches various empty values', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => ['empty']], ['other' => 'exists']))->toBeTrue();   // absent = null
        expect($engine->matches(['field' => ['empty']], ['field' => null]))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => '']))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => 0]))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => '0']))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => false]))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => []]))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => 'value']))->toBeFalse();
    });

    test('not_empty operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => ['not_empty']], ['field' => 'value']))->toBeTrue();
        expect($engine->matches(['field' => ['not_empty']], ['field' => 1]))->toBeTrue();
        expect($engine->matches(['field' => ['not_empty']], ['field' => true]))->toBeTrue();
        expect($engine->matches(['field' => ['not_empty']], ['field' => [1]]))->toBeTrue();
        expect($engine->matches(['field' => ['not_empty']], ['field' => null]))->toBeFalse();
        expect($engine->matches(['field' => ['not_empty']], ['field' => '']))->toBeFalse();
        expect($engine->matches(['field' => ['not_empty']], ['field' => 0]))->toBeFalse();
        expect($engine->matches(['field' => ['not_empty']], ['field' => '0']))->toBeFalse();
        expect($engine->matches(['field' => ['not_empty']], ['field' => false]))->toBeFalse();
        expect($engine->matches(['field' => ['not_empty']], ['field' => []]))->toBeFalse();
    });
});
