<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\ConditionEngine;

/**
 * Tests for null-value handling in comparison operators.
 *
 * PHP 8.5 strict comparison edge case: comparing numeric values to null
 * should not produce incorrect results (e.g., 5 > null was evaluating to
 * true due to PHP's type juggling). After the fix, null comparison values
 * are guarded explicitly.
 */
it('rejects comparison operators when value is null', function (string $operator, bool $expected): void {
    $engine = new ConditionEngine();

    // field=5, condition=["operator", null] → should return false
    expect($engine->matches(['amount' => [$operator, null]], ['amount' => 100]))
        ->toBe($expected);
})->with([
    ['>', false],
    ['>=', false],
    ['<', false],
    ['<=', false],
]);

it('rejects comparison operators when actual value is null', function (string $operator, bool $expected): void {
    $engine = new ConditionEngine();

    // field=null, condition=["operator", 50] → should return false
    expect($engine->matches(['amount' => [$operator, 50]], ['amount' => null]))
        ->toBe($expected);
})->with([
    ['>', false],
    ['>=', false],
    ['<', false],
    ['<=', false],
]);

it('correctly evaluates comparisons when both actual and value are non-null', function (): void {
    $engine = new ConditionEngine();

    expect($engine->matches(['amount' => ['>', 50]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['amount' => ['>', 50]], ['amount' => 25]))->toBeFalse();
    expect($engine->matches(['amount' => ['>=', 50]], ['amount' => 50]))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 50]], ['amount' => 25]))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 50]], ['amount' => 50]))->toBeTrue();
});

it('correctly evaluates between with null value', function (): void {
    $engine = new ConditionEngine();

    // between with null value → should return false
    expect($engine->matches(['amount' => ['between', null]], ['amount' => 50]))->toBeFalse();
});

it('correctly evaluates between with non-null min and max', function (): void {
    $engine = new ConditionEngine();

    expect($engine->matches(['amount' => ['between', [10, 100]]], ['amount' => 50]))->toBeTrue();
    expect($engine->matches(['amount' => ['between', [10, 100]]], ['amount' => 5]))->toBeFalse();
    expect($engine->matches(['amount' => ['between', [100, 10]]], ['amount' => 50]))->toBeTrue(); // auto-normalize inverted range
});

it('in operator with null value returns false', function (): void {
    $engine = new ConditionEngine();

    expect($engine->matches(['status' => ['in', null]], ['status' => 'active']))->toBeFalse();
});

it('not_in operator with null value returns false', function (): void {
    $engine = new ConditionEngine();

    expect($engine->matches(['status' => ['not_in', null]], ['status' => 'active']))->toBeFalse();
});

it('strict equals handles null vs string comparison', function (): void {
    $engine = new ConditionEngine();

    // null field = null value → false (different types: null vs string)
    expect($engine->matches(['field' => 'null_value'], ['field' => null]))->toBeFalse();

    // Using the 'null' operator
    expect($engine->matches(['field' => ['null']], ['field' => null]))->toBeTrue();
    expect($engine->matches(['field' => ['not_null']], ['field' => 'something']))->toBeTrue();
});
