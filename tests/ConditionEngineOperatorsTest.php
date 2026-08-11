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

describe('ConditionEngine additional operator coverage', function (): void {
    test('not_contains operator with string', function (): void {
        expect($this->engine->matches(
            ['message' => ['not_contains', 'error']],
            ['message' => 'all good'],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['message' => ['not_contains', 'error']],
            ['message' => 'error occurred'],
        ))->toBeFalse();
    });

    test('not_contains operator with array', function (): void {
        expect($this->engine->matches(
            ['tags' => ['not_contains', 'spam']],
            ['tags' => ['important', 'urgent']],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['tags' => ['not_contains', 'spam']],
            ['tags' => ['spam', 'important']],
        ))->toBeFalse();
    });

    test('not_null operator', function (): void {
        expect($this->engine->matches(
            ['deleted_at' => ['not_null']],
            ['deleted_at' => '2024-01-01'],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['deleted_at' => ['not_null']],
            ['deleted_at' => null],
        ))->toBeFalse();
    });

    test('empty operator', function (): void {
        expect($this->engine->matches(
            ['notes' => ['empty']],
            ['notes' => ''],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['notes' => ['empty']],
            ['notes' => null],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['notes' => ['empty']],
            ['notes' => 0],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['notes' => ['empty']],
            ['notes' => 'hello'],
        ))->toBeFalse();
    });

    test('not_empty operator', function (): void {
        expect($this->engine->matches(
            ['notes' => ['not_empty']],
            ['notes' => 'hello'],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['notes' => ['not_empty']],
            ['notes' => ''],
        ))->toBeFalse();

        expect($this->engine->matches(
            ['notes' => ['not_empty']],
            ['notes' => null],
        ))->toBeFalse();
    });

    test('ends_with operator', function (): void {
        expect($this->engine->matches(
            ['email' => ['ends_with', '@example.com']],
            ['email' => 'user@example.com'],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['email' => ['ends_with', '@example.com']],
            ['email' => 'user@other.com'],
        ))->toBeFalse();

        expect($this->engine->matches(
            ['email' => ['ends_with', '@example.com']],
            ['email' => 123],
        ))->toBeFalse();
    });

    test('not_in operator', function (): void {
        expect($this->engine->matches(
            ['status' => ['not_in', ['banned', 'deleted']]],
            ['status' => 'active'],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['status' => ['not_in', ['banned', 'deleted']]],
            ['status' => 'banned'],
        ))->toBeFalse();
    });

    test('strict equality with === operator', function (): void {
        expect($this->engine->matches(
            ['count' => ['===', 5]],
            ['count' => 5],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['count' => ['===', 5]],
            ['count' => '5'],
        ))->toBeFalse();
    });

    test('strict inequality with !== operator', function (): void {
        expect($this->engine->matches(
            ['count' => ['!==', '5']],
            ['count' => 5],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['count' => ['!==', 5]],
            ['count' => 5],
        ))->toBeFalse();
    });

    test('comparison operators with null actual return false', function (): void {
        expect($this->engine->matches(['value' => ['>', 0]], ['value' => null]))->toBeFalse();
        expect($this->engine->matches(['value' => ['>=', 0]], ['value' => null]))->toBeFalse();
        expect($this->engine->matches(['value' => ['<', 0]], ['value' => null]))->toBeFalse();
        expect($this->engine->matches(['value' => ['<=', 0]], ['value' => null]))->toBeFalse();
    });

    test('comparison operators with null value return false', function (): void {
        expect($this->engine->matches(['value' => ['>', null]], ['value' => 5]))->toBeFalse();
    });

    test('between with null range values returns false', function (): void {
        expect($this->engine->matches(
            ['value' => ['between', [null, 10]]],
            ['value' => 5],
        ))->toBeFalse();
    });

    test('deep nested dot notation', function (): void {
        expect($this->engine->matches(
            ['user.profile.settings.theme' => 'dark'],
            ['user' => ['profile' => ['settings' => ['theme' => 'dark']]]],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['user.profile.settings.theme' => 'dark'],
            ['user' => ['profile' => ['settings' => ['theme' => 'light']]]],
        ))->toBeFalse();
    });

    test('in operator is strict (type-aware)', function (): void {
        // int 1 should not match string '1' with strict comparison
        expect($this->engine->matches(
            ['value' => ['in', [1, 2, 3]]],
            ['value' => 1],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['value' => ['in', [1, 2, 3]]],
            ['value' => '1'],
        ))->toBeFalse();
    });

    test('empty operator condition array returns false', function (): void {
        // [] as expected value → empty array in operator syntax → false
        expect($this->engine->matches(
            ['status' => []],
            ['status' => 'anything'],
        ))->toBeFalse();
    });
});
