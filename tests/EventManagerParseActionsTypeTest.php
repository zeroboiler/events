<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ActionResolver;
use Illuminate\Container\Container;

/**
 * Tests that EventManager::parseActions() returns correctly typed entries.
 *
 * Each entry in the returned list must be either:
 * - A string (class FQN)
 * - An array with a 'class' key (and optional 'params')
 *
 * @see EventManager::parseActions()
 */
describe('EventManager::parseActions — return type validation', function (): void {
    test('simple class name returns list of one string', function (): void {
        $em = new EventManager(
            new ConditionEngine,
            new ActionResolver(app()),
            app(),
        );

        $result = $this->invokeMethod($em, 'parseActions', ['App\\Actions\\SendNotification']);

        expect($result)->toBeArray()
            ->toHaveCount(1)
            ->and($result[0])->toBe('App\\Actions\\SendNotification');
    });

    test('JSON array of class names returns list of strings', function (): void {
        $em = new EventManager(
            new ConditionEngine,
            new ActionResolver(app()),
            app(),
        );

        $result = $this->invokeMethod($em, 'parseActions', [
            json_encode(['App\\Actions\\Foo', 'App\\Actions\\Bar']),
        ]);

        expect($result)->toBeArray()
            ->toHaveCount(2)
            ->and($result[0])->toBe('App\\Actions\\Foo')
            ->and($result[1])->toBe('App\\Actions\\Bar');
    });

    test('JSON object with class+params returns list of one array', function (): void {
        $em = new EventManager(
            new ConditionEngine,
            new ActionResolver(app()),
            app(),
        );

        $result = $this->invokeMethod($em, 'parseActions', [
            json_encode(['class' => 'App\\Actions\\Webhook', 'params' => ['url' => 'https://x.com']]),
        ]);

        expect($result)->toBeArray()
            ->toHaveCount(1)
            ->and($result[0])->toBeArray()
            ->and($result[0])->toHaveKey('class')
            ->and($result[0]['class'])->toBe('App\\Actions\\Webhook')
            ->and($result[0])->toHaveKey('params')
            ->and($result[0]['params'])->toBe(['url' => 'https://x.com']);
    });

    test('JSON classes+params format returns list of arrays with shared params', function (): void {
        $em = new EventManager(
            new ConditionEngine,
            new ActionResolver(app()),
            app(),
        );

        $result = $this->invokeMethod($em, 'parseActions', [
            json_encode([
                'classes' => ['App\\Actions\\Foo', 'App\\Actions\\Bar'],
                'params' => ['topic' => 'orders'],
            ]),
        ]);

        expect($result)->toBeArray()
            ->toHaveCount(2)
            ->and($result[0])->toBeArray()
            ->and($result[0]['class'])->toBe('App\\Actions\\Foo')
            ->and($result[0]['params'])->toBe(['topic' => 'orders'])
            ->and($result[1])->toBeArray()
            ->and($result[1]['class'])->toBe('App\\Actions\\Bar')
            ->and($result[1]['params'])->toBe(['topic' => 'orders']);
    });

    test('JSON array of objects returns list of arrays', function (): void {
        $em = new EventManager(
            new ConditionEngine,
            new ActionResolver(app()),
            app(),
        );

        $result = $this->invokeMethod($em, 'parseActions', [
            json_encode([
                ['class' => 'App\\Actions\\A', 'params' => ['k' => 1]],
                ['class' => 'App\\Actions\\B'],
            ]),
        ]);

        expect($result)->toBeArray()
            ->toHaveCount(2)
            ->and($result[0])->toBeArray()
            ->and($result[0]['class'])->toBe('App\\Actions\\A')
            ->and($result[1])->toBeArray()
            ->and($result[1]['class'])->toBe('App\\Actions\\B');
    });

    test('non-JSON string returns list with the original string', function (): void {
        $em = new EventManager(
            new ConditionEngine,
            new ActionResolver(app()),
            app(),
        );

        $result = $this->invokeMethod($em, 'parseActions', ['App\\Actions\\Simple']);

        expect($result)->toBe(['App\\Actions\\Simple']);
    });

    test('every entry is either a string or an array with class key', function (): void {
        $em = new EventManager(
            new ConditionEngine,
            new ActionResolver(app()),
            app(),
        );

        // Test with mixed array of objects
        $result = $this->invokeMethod($em, 'parseActions', [
            json_encode([
                ['class' => 'App\\A'],
                'App\\B',
                ['class' => 'App\\C', 'params' => []],
            ]),
        ]);

        foreach ($result as $i => $entry) {
            if (is_array($entry)) {
                expect($entry)->toHaveKey('class')
                    ->and($entry['class'])->toBeString();
            } else {
                expect($entry)->toBeString();
            }
        }

        expect($result)->toHaveCount(3);
    });
});

// Helper to invoke protected methods via reflection
uses()->beforeEach(function (): void {
    $this->invokeMethod = function (object $obj, string $method, array $args = []): mixed {
        $ref = new ReflectionMethod($obj, $method);

        return $ref->invoke($obj, ...$args);
    };
});
