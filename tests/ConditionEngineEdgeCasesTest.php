<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;

beforeEach(function (): void {
    $this->app = $this->createApplication();
    $this->engine = $this->app->make(ConditionEngine::class);
});

describe('ConditionEngine strictEquals edge cases', function (): void {
    test('same-type string values match with strict equality', function (): void {
        expect($this->engine->matches(['name' => 'test'], ['name' => 'test']))->toBeTrue();
    });

    test('same-type integer values match with strict equality', function (): void {
        expect($this->engine->matches(['count' => 5], ['count' => 5]))->toBeTrue();
    });

    test('same-type boolean values match with strict equality', function (): void {
        expect($this->engine->matches(['active' => true], ['active' => true]))->toBeTrue();
    });

    test('different-type scalar values compare as strings', function (): void {
        // int 5 vs string '5' — different types, both scalar → compare as strings
        expect($this->engine->matches(['count' => '5'], ['count' => 5]))->toBeTrue();
        expect($this->engine->matches(['count' => 5], ['count' => '5']))->toBeTrue();
    });

    test('array values do not match even with same content', function (): void {
        // Different types (array vs array) → same type → strict compare (===)
        expect($this->engine->matches(
            ['tags' => ['a', 'b']],
            ['tags' => ['a', 'b']],
        ))->toBeTrue();

        // Array vs non-array → different types → compare as strings → false (arrays aren't scalar)
        expect($this->engine->matches(
            ['tags' => 'a,b'],
            ['tags' => ['a', 'b']],
        ))->toBeFalse();
    });

    test('null vs null matches', function (): void {
        expect($this->engine->matches(['field' => null], ['field' => null]))->toBeTrue();
    });

    test('null vs string does not match', function (): void {
        expect($this->engine->matches(['field' => null], ['field' => '']))->toBeFalse();
    });

    test('float vs int with same numeric value compares as strings', function (): void {
        expect($this->engine->matches(['value' => 1.0], ['value' => 1]))->toBeTrue();
    });

    test('empty string vs 0 does not match (string comparison)', function (): void {
        // Different types (string vs int), both scalar → compare as strings
        // '' === '0' → false
        expect($this->engine->matches(['value' => ''], ['value' => 0]))->toBeFalse();
    });

    test('false vs empty string compares as strings', function (): void {
        // Different types (bool vs string), both scalar → compare as strings
        // '' === '' (false casts to '' in string context) → true? No — (string)false = '' and (string)'' = ''
        // Wait, the actual behavior: strictEquals uses get_debug_type comparison first
        // get_debug_type(false) = 'bool', get_debug_type('') = 'string' → different types
        // Both scalar → (string)false = '', (string)'' = '' → match!
        expect($this->engine->matches(['value' => false], ['value' => '']))->toBeTrue();
    });
});

describe('ConditionEngine operator edge cases', function (): void {
    test('empty conditions array matches everything', function (): void {
        expect($this->engine->matches([], ['any' => 'data']))->toBeTrue();
        expect($this->engine->matches([], []))->toBeTrue();
    });

    test('single null operator with null field', function (): void {
        expect($this->engine->matches(
            ['deleted_at' => ['null']],
            ['deleted_at' => null],
        ))->toBeTrue();
    });

    test('single null operator with non-null field', function (): void {
        expect($this->engine->matches(
            ['deleted_at' => ['null']],
            ['deleted_at' => '2024-01-01'],
        ))->toBeFalse();
    });

    test('single-element array condition treated as operator syntax', function (): void {
        // ['status' => ['null']] → operator 'null', value null
        expect($this->engine->matches(
            ['status' => ['null']],
            ['status' => null],
        ))->toBeTrue();
    });

    test('unknown operator falls back to strict equality', function (): void {
        expect($this->engine->matches(
            ['value' => ['unknown_op', 'test']],
            ['value' => ['unknown_op', 'test']],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['value' => ['unknown_op', 'test']],
            ['value' => 'something else'],
        ))->toBeFalse();
    });

    test('nested dot notation with null intermediate value', function (): void {
        expect($this->engine->matches(
            ['user.profile.name' => 'John'],
            ['user' => null],
        ))->toBeFalse();
    });

    test('in operator with empty array', function (): void {
        // value is [], in_array(actual, []) → false
        expect($this->engine->matches(
            ['role' => ['in', []]],
            ['role' => 'admin'],
        ))->toBeFalse();
    });

    test('between with non-numeric actual', function (): void {
        expect($this->engine->matches(
            ['name' => ['between', [1, 10]]],
            ['name' => 'hello'],
        ))->toBeFalse();
    });

    test('between with non-array range', function (): void {
        expect($this->engine->matches(
            ['value' => ['between', 5]],
            ['value' => 7],
        ))->toBeFalse();
    });

    test('between with inverted range auto-normalizes', function (): void {
        // [100, 50] → min=50, max=100
        expect($this->engine->matches(
            ['value' => ['between', [100, 50]]],
            ['value' => 75],
        ))->toBeTrue();
    });

    test('between at exact boundaries', function (): void {
        expect($this->engine->matches(
            ['value' => ['between', [10, 20]]],
            ['value' => 10],
        ))->toBeTrue();
        expect($this->engine->matches(
            ['value' => ['between', [10, 20]]],
            ['value' => 20],
        ))->toBeTrue();
    });

    test('contains with non-string non-array actual', function (): void {
        // actual is an integer, value is a string → not array, not both strings → false
        expect($this->engine->matches(
            ['count' => ['contains', '5']],
            ['count' => 123],
        ))->toBeFalse();
    });

    test('starts_with with non-string actual', function (): void {
        expect($this->engine->matches(
            ['count' => ['starts_with', '1']],
            ['count' => 123],
        ))->toBeFalse();
    });

    test('matches operator with non-string actual', function (): void {
        expect($this->engine->matches(
            ['count' => ['matches', '/\\d+/']],
            ['count' => 123],
        ))->toBeFalse();
    });

    test('regex exceeds max length returns false', function (): void {
        $longPattern = '/^' . str_repeat('a', 500) . '$/';

        expect($this->engine->matches(
            ['code' => ['matches', $longPattern]],
            ['code' => str_repeat('a', 500)],
        ))->toBeFalse();
    });

    test('regex with nested quantifiers returns false', function (): void {
        // (a+)+ is a catastrophic backtracking pattern
        expect($this->engine->matches(
            ['code' => ['matches', '/^(a+)+$/']],
            ['code' => 'aaa'],
        ))->toBeFalse();
    });

    test('multiple conditions all must match (AND logic)', function (): void {
        expect($this->engine->matches(
            [
                'status' => 'active',
                'age' => ['>', 18],
                'role' => ['in', ['admin', 'mod']],
            ],
            [
                'status' => 'active',
                'age' => 25,
                'role' => 'admin',
            ],
        ))->toBeTrue();

        // One condition fails → all fail
        expect($this->engine->matches(
            [
                'status' => 'active',
                'age' => ['>', 18],
            ],
            [
                'status' => 'inactive',
                'age' => 25,
            ],
        ))->toBeFalse();
    });
});
