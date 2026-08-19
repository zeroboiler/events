<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;

describe('ConditionEngine::getNestedValue', function () {
    it('returns top-level values directly', function () {
        $engine = new ConditionEngine;
        // Use matches() which internally calls getNestedValue
        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
        expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();
    });

    it('resolves nested dot-notation fields', function () {
        $engine = new ConditionEngine;
        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin']],
        ))->toBeTrue();

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'user']],
        ))->toBeFalse();
    });

    it('returns null for missing nested keys', function () {
        $engine = new ConditionEngine;
        // Missing 'user' key entirely
        expect($engine->matches(
            ['user.role' => ['null']],
            ['other' => 'value'],
        ))->toBeTrue(); // null == null condition
    });

    it('resolves deeply nested fields', function () {
        $engine = new ConditionEngine;
        expect($engine->matches(
            ['order.customer.address.city' => 'Istanbul'],
            ['order' => ['customer' => ['address' => ['city' => 'Istanbul']]]],
        ))->toBeTrue();
    });

    it('returns null when intermediate key is not an array', function () {
        $engine = new ConditionEngine;
        // 'user' is a string, not an array, so 'user.role' should return null
        expect($engine->matches(
            ['user.role' => ['null']],
            ['user' => 'not_an_array'],
        ))->toBeTrue(); // null matches null condition
    });

    it('handles numeric keys in nested arrays', function () {
        $engine = new ConditionEngine;
        // Can't use numeric dot notation reliably, but this tests the path
        $payload = ['items' => [['name' => 'Widget'], ['name' => 'Gadget']]];
        // Accessing items.0.name should work
        expect($engine->matches(
            ['items.0.name' => 'Widget'],
            $payload,
        ))->toBeTrue();
    });
});
