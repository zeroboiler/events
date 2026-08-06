<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\WildcardMatcher;

describe('ConditionEngine — nested value access', function (): void {
    test('getNestedValue returns null for non-existent top-level key', function (): void {
        $engine = app(ConditionEngine::class);

        // Use reflection to test the protected method
        $method = new ReflectionMethod(ConditionEngine::class, 'getNestedValue');

        expect($method->invoke($engine, ['foo' => 'bar'], 'baz'))->toBeNull();
    });

    test('getNestedValue returns null for deeply nested missing key', function (): void {
        $engine = app(ConditionEngine::class);

        $method = new ReflectionMethod(ConditionEngine::class, 'getNestedValue');

        expect($method->invoke($engine, ['a' => ['b' => 'c']], 'a.b.d'))->toBeNull();
    });

    test('getNestedValue traverses three levels deep', function (): void {
        $engine = app(ConditionEngine::class);

        $method = new ReflectionMethod(ConditionEngine::class, 'getNestedValue');

        expect($method->invoke($engine, ['a' => ['b' => ['c' => 'found']]], 'a.b.c'))->toBe('found');
    });

    test('getNestedValue returns the value when intermediate keys are not arrays', function (): void {
        $engine = app(ConditionEngine::class);

        $method = new ReflectionMethod(ConditionEngine::class, 'getNestedValue');

        // 'a.b' where 'a' is a string — should return null (can't traverse)
        expect($method->invoke($engine, ['a' => 'string_value'], 'a.b'))->toBeNull();
    });
});

describe('ConditionEngine — strict equality edge cases', function (): void {
    test('float equals int with = operator (cross-type scalar)', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['value' => 5], ['value' => 5.0]))
            ->toBeTrue();
    });

    test('float equals string 5 with = operator (cross-type scalar)', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['value' => 5], ['value' => '5']))
            ->toBeTrue();
    });

    test('array does not equal string with = operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['tags' => 'urgent'], ['tags' => ['urgent']]))
            ->toBeFalse();
    });

    test('null does not equal empty string with = operator', function (): void {
        $engine = app(ConditionEngine::class);

        // null vs string — not both scalar? Actually null is not scalar in PHP
        expect($engine->matches(['value' => ''], ['value' => null]))
            ->toBeFalse();
    });

    test('null does not equal 0 with = operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['value' => 0], ['value' => null]))
            ->toBeFalse();
    });
});

describe('WildcardMatcher — extractWildcards edge cases', function (): void {
    test('extractWildcards returns empty for double wildcard pattern', function (): void {
        // ** matches across segments, so segment counts differ
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        expect($result)->toBe([]);
    });

    test('extractWildcards works with pattern containing no wildcards on exact match', function (): void {
        $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');

        expect($result)->toBe([]);
    });

    test('extractWildcards handles multiple single wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('a.*.b.*.c', 'a.x.b.y.c');

        expect($result)->toBe(['x', 'y']);
    });
});

describe('EscapesWildcardLike — percent in pattern', function (): void {
    it('escapes percent signs in pattern', function (): void {
        $trait = new class
        {
            use EscapesWildcardLike;
        };

        $method = new ReflectionMethod($trait, 'wildcardToLike');

        // Pattern with literal % that should be escaped
        expect($method->invoke($trait, 'order.%.*'))
            ->toBe('order.\\%%.%');
    });

    it('escapes all three special characters together', function (): void {
        $trait = new class
        {
            use EscapesWildcardLike;
        };

        $method = new ReflectionMethod($trait, 'wildcardToLike');

        // Pattern: user_%\*.placed (backslash, percent, underscore, wildcard)
        expect($method->invoke($trait, 'user_%\\*.placed'))
            ->toBe('user\\_%%\\%.placed');
    });
});
