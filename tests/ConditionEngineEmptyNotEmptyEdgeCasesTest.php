<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;

describe('ConditionEngine empty/not_empty operator edge cases', function (): void {
    $engine = new ConditionEngine;

    describe('empty operator', function () use ($engine): void {
        it('matches null', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => null]))->toBeTrue();
        });

        it('matches empty string', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => '']))->toBeTrue();
        });

        it('matches string "0"', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => '0']))->toBeTrue();
        });

        it('matches integer 0', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => 0]))->toBeTrue();
        });

        it('matches false', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => false]))->toBeTrue();
        });

        it('matches empty array', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => []]))->toBeTrue();
        });

        it('does NOT match float 0.0 (not in the empty spec)', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => 0.0]))->toBeFalse();
        });

        it('does NOT match float -0.0', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => -0.0]))->toBeFalse();
        });

        it('does NOT match a non-empty string', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => 'hello']))->toBeFalse();
        });

        it('does NOT match integer 1', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => 1]))->toBeFalse();
        });

        it('does NOT match a non-empty array', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => ['a']]))->toBeFalse();
        });

        it('does NOT match true', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], ['x' => true]))->toBeFalse();
        });

        it('matches missing key (null via getNestedValue)', function () use ($engine): void {
            expect($engine->matches(['x' => ['empty']], []))->toBeTrue();
        });
    });

    describe('not_empty operator', function () use ($engine): void {
        it('does NOT match null', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => null]))->toBeFalse();
        });

        it('does NOT match empty string', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => '']))->toBeFalse();
        });

        it('does NOT match string "0"', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => '0']))->toBeFalse();
        });

        it('does NOT match integer 0', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => 0]))->toBeFalse();
        });

        it('does NOT match false', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => false]))->toBeFalse();
        });

        it('does NOT match empty array', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => []]))->toBeFalse();
        });

        it('matches float 0.0 (not in the empty spec)', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => 0.0]))->toBeTrue();
        });

        it('matches a non-empty string', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => 'hello']))->toBeTrue();
        });

        it('matches integer 1', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => 1]))->toBeTrue();
        });

        it('matches a non-empty array', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => ['a']]))->toBeTrue();
        });

        it('matches true', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], ['x' => true]))->toBeTrue();
        });

        it('does NOT match missing key (null via getNestedValue)', function () use ($engine): void {
            expect($engine->matches(['x' => ['not_empty']], []))->toBeFalse();
        });
    });

    describe('nested dot-notation with empty/not_empty', function () use ($engine): void {
        it('evaluates empty on nested field', function () use ($engine): void {
            expect($engine->matches(
                ['user.name' => ['empty']],
                ['user' => ['name' => '']],
            ))->toBeTrue();
        });

        it('evaluates not_empty on nested field', function () use ($engine): void {
            expect($engine->matches(
                ['user.name' => ['not_empty']],
                ['user' => ['name' => 'John']],
            ))->toBeTrue();
        });

        it('evaluates empty on deep nested field', function () use ($engine): void {
            expect($engine->matches(
                ['order.items' => ['empty']],
                ['order' => ['items' => []]],
            ))->toBeTrue();
        });

        it('evaluates empty on missing nested key', function () use ($engine): void {
            expect($engine->matches(
                ['user.profile.bio' => ['empty']],
                ['user' => ['name' => 'John']],
            ))->toBeTrue();
        });
    });

    describe('combined conditions with empty/not_empty', function () use ($engine): void {
        it('AND logic: both conditions must match', function () use ($engine): void {
            expect($engine->matches(
                ['status' => ['empty'], 'notes' => ['empty']],
                ['status' => '', 'notes' => ''],
            ))->toBeTrue();

            expect($engine->matches(
                ['status' => ['empty'], 'notes' => ['empty']],
                ['status' => '', 'notes' => 'has text'],
            ))->toBeFalse();
        });

        it('mixed operators with empty', function () use ($engine): void {
            expect($engine->matches(
                ['amount' => ['>', 100], 'notes' => ['empty']],
                ['amount' => 150, 'notes' => ''],
            ))->toBeTrue();

            expect($engine->matches(
                ['amount' => ['>', 100], 'notes' => ['empty']],
                ['amount' => 50, 'notes' => ''],
            ))->toBeFalse();
        });
    });
});
