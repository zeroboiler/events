<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ReflectionMethod;
use ZeroBoiler\Events\EventManager;

uses(TestCase::class);

describe('EventManager::sanitizePayloadForQueue edge cases', function (): void {
    test('removes Closure values and replaces with placeholder', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $method->setAccessible(true);

        $payload = [
            'callback' => fn () => 'hello',
            'normal' => 'value',
        ];

        $result = $method->invoke($manager, $payload);

        expect($result['normal'])->toBe('value');
        expect($result['callback'])->toBe('[stripped:Closure]');
    });

    test('removes object values and replaces with placeholder', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $method->setAccessible(true);

        $obj = new \stdClass();
        $obj->foo = 'bar';

        $payload = [
            'object' => $obj,
            'string' => 'kept',
        ];

        $result = $method->invoke($manager, $payload);

        expect($result['string'])->toBe('kept');
        expect($result['object'])->toBe('[stripped:stdClass]');
    });

    test('preserves all scalar types', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $method->setAccessible(true);

        $payload = [
            'int' => 42,
            'float' => 3.14,
            'string' => 'hello',
            'bool_true' => true,
            'bool_false' => false,
            'null' => null,
        ];

        $result = $method->invoke($manager, $payload);

        expect($result['int'])->toBe(42);
        expect($result['float'])->toBe(3.14);
        expect($result['string'])->toBe('hello');
        expect($result['bool_true'])->toBe(true);
        expect($result['bool_false'])->toBe(false);
        expect($result['null'])->toBeNull();
    });

    test('recursively sanitizes nested arrays', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $method->setAccessible(true);

        $payload = [
            'level1' => [
                'level2' => [
                    'nested_obj' => new \stdClass(),
                    'nested_string' => 'safe',
                ],
            ],
        ];

        $result = $method->invoke($manager, $payload);

        expect($result['level1']['level2']['nested_string'])->toBe('safe');
        expect($result['level1']['level2']['nested_obj'])->toBe('[stripped:stdClass]');
    });

    test('handles empty payload', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $method->setAccessible(true);

        $result = $method->invoke($manager, []);

        expect($result)->toBe([]);
    });

    test('preserves arrays of scalars', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $method->setAccessible(true);

        $payload = [
            'tags' => ['urgent', 'important', 'review'],
            'ids' => [1, 2, 3],
        ];

        $result = $method->invoke($manager, $payload);

        expect($result['tags'])->toBe(['urgent', 'important', 'review']);
        expect($result['ids'])->toBe([1, 2, 3]);
    });

    test('removes resource values', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $method->setAccessible(true);

        $resource = fopen('php://memory', 'r');

        $payload = [
            'file' => $resource,
            'name' => 'test',
        ];

        $result = $method->invoke($manager, $payload);

        expect($result['name'])->toBe('test');
        expect($result['file'])->toBe('[stripped:resource]');

        if (is_resource($resource)) {
            fclose($resource);
        }
    });
});
