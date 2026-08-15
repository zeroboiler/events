<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

describe('ConditionEngine empty and edge case conditions', function (): void {
    it('returns true for empty conditions array (no constraints)', function (): void {
        $engine = new \ZeroBoiler\Events\ConditionEngine;

        expect($engine->matches([], ['any' => 'data']))->toBeTrue();
        expect($engine->matches([], []))->toBeTrue();
    });

    it('returns true for empty payload when no conditions', function (): void {
        $engine = new \ZeroBoiler\Events\ConditionEngine;

        expect($engine->matches([], []))->toBeTrue();
    });

    it('returns false for empty operator array (field => [])', function (): void {
        $engine = new \ZeroBoiler\Events\ConditionEngine;

        // An empty operator array should return false (no operator specified)
        expect($engine->matches(['status' => []], ['status' => 'active']))->toBeFalse();
    });

    it('handles non-string operator gracefully (returns false)', function (): void {
        $engine = new \ZeroBoiler\Events\ConditionEngine;

        // Array with non-string first element
        expect($engine->matches(['field' => [123, 'value']], ['field' => 'value']))->toBeFalse();
    });

    it('returns true for exact null match with null operator', function (): void {
        $engine = new \ZeroBoiler\Events\ConditionEngine;

        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
    });

    it('returns false for null match on non-null value', function (): void {
        $engine = new \ZeroBoiler\Events\ConditionEngine;

        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']))->toBeFalse();
    });

    it('handles nested dot notation with intermediate null', function (): void {
        $engine = new \ZeroBoiler\Events\ConditionEngine;

        // data.user is null, accessing data.user.name should return null
        expect($engine->matches(['user.role' => ['null']], ['user' => null]))->toBeTrue();
    });

    it('not_null operator rejects null values', function (): void {
        $engine = new \ZeroBoiler\Events\ConditionEngine;

        expect($engine->matches(['email' => ['not_null']], ['email' => null]))->toBeFalse();
        expect($engine->matches(['email' => ['not_null']], ['email' => 'test@example.com']))->toBeTrue();
    });
});
