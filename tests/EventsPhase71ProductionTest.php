<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    $this->app = $this->createApplication();
    $this->engine = $this->app->make(ConditionEngine::class);
});

describe('Phase 71 Production — ConditionEngine missing field', function (): void {
    test('condition field not in payload returns false for equality', function (): void {
        expect($this->engine->matches(
            ['nonexistent' => 'value'],
            ['existing' => 'other'],
        ))->toBeFalse();
    });

    test('condition field not in payload returns false for comparison operators', function (): void {
        expect($this->engine->matches(
            ['nonexistent' => ['>', 0]],
            ['existing' => 'other'],
        ))->toBeFalse();
    });

    test('condition field not in payload returns true for null operator', function (): void {
        expect($this->engine->matches(
            ['nonexistent' => ['null']],
            ['existing' => 'other'],
        ))->toBeTrue();
    });

    test('condition field not in payload returns false for not_null operator', function (): void {
        expect($this->engine->matches(
            ['nonexistent' => ['not_null']],
            ['existing' => 'other'],
        ))->toBeFalse();
    });

    test('condition field not in payload returns true for empty operator', function (): void {
        // null is considered empty
        expect($this->engine->matches(
            ['nonexistent' => ['empty']],
            ['existing' => 'other'],
        ))->toBeTrue();
    });

    test('condition field not in payload returns false for not_empty operator', function (): void {
        expect($this->engine->matches(
            ['nonexistent' => ['not_empty']],
            ['existing' => 'other'],
        ))->toBeFalse();
    });
});

describe('Phase 71 Production — ConditionEngine deep nesting', function (): void {
    test('triple-nested dot notation resolves correctly', function (): void {
        expect($this->engine->matches(
            ['a.b.c' => 'deep'],
            ['a' => ['b' => ['c' => 'deep']]],
        ))->toBeTrue();
    });

    test('triple-nested with null intermediate returns false', function (): void {
        expect($this->engine->matches(
            ['a.b.c' => 'deep'],
            ['a' => ['b' => null]],
        ))->toBeFalse();
    });

    test('triple-nested with missing intermediate returns false', function (): void {
        expect($this->engine->matches(
            ['a.b.c' => 'deep'],
            ['a' => ['d' => 'wrong']],
        ))->toBeFalse();
    });

    test('field at root with dot in key is not split', function (): void {
        // getNestedValue splits on dots, so 'a.b.c' as a key
        // requires nested array structure, not a flat key with dots
        expect($this->engine->matches(
            ['a.b.c' => 'value'],
            ['a.b.c' => 'value'],
        ))->toBeFalse();
    });
});

describe('Phase 71 Production — WildcardMatcher edge cases', function (): void {
    test('pattern with only asterisks returns false for empty event', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('event with trailing dot does not match single-segment wildcard', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.'))->toBeFalse();
    });

    test('empty pattern with empty event', function (): void {
        expect(WildcardMatcher::matches('', ''))->toBeTrue();
    });

    test('single-dot pattern matches single-dot event', function (): void {
        expect(WildcardMatcher::matches('.', '.'))->toBeTrue();
    });

    test('extractWildcards with no wildcards returns empty', function (): void {
        expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))
            ->toBeEmpty();
    });

    test('findMatchingPatterns preserves order', function (): void {
        $patterns = ['a.*', 'b.*', 'c.*'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'a.test');
        expect($result)->toBe(['a.*']);
    });
});

describe('Phase 71 Production — ConditionEngineContract type', function (): void {
    test('ConditionEngine implements ConditionEngineContract', function (): void {
        expect($this->engine)->toBeInstanceOf(ConditionEngineContract::class);
    });

    test('contract resolve returns same singleton', function (): void {
        $first = $this->app->make(ConditionEngineContract::class);
        $second = $this->app->make(ConditionEngineContract::class);
        expect($first)->toBe($second);
    });
});
