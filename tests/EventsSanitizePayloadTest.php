<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Tests for EventManager::sanitizePayloadForQueue() and parseActions().
 *
 * These are protected methods, so we test them via reflection.
 */
describe('EventManager Internal Methods', function (): void {
    test('sanitizePayloadForQueue strips objects and keeps scalars', function (): void {
        $manager = app()->make(EventManager::class);
        $ref = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $ref->setAccessible(true);

        $obj = new \stdClass;
        $obj->foo = 'bar';

        $result = $ref->invoke($manager, [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'object' => $obj,
            'array' => ['nested' => 'value'],
        ]);

        expect($result)->toBe([
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'object' => '[stripped:stdClass]',
            'array' => ['nested' => 'value'],
        ]);
    });

    test('sanitizePayloadForQueue handles nested objects', function (): void {
        $manager = app()->make(EventManager::class);
        $ref = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
        $ref->setAccessible(true);

        $result = $ref->invoke($manager, [
            'level1' => [
                'level2' => [
                    'obj' => new \stdClass,
                ],
            ],
        ]);

        expect($result)->toBe([
            'level1' => [
                'level2' => [
                    'obj' => '[stripped:stdClass]',
                ],
            ],
        ]);
    });

    test('parseActions handles single class name', function (): void {
        $manager = app()->make(EventManager::class);
        $ref = new ReflectionMethod($manager, 'parseActions');
        $ref->setAccessible(true);

        $result = $ref->invoke($manager, 'App\\Actions\\SendNotification');

        expect($result)->toBe(['App\\Actions\\SendNotification']);
    });

    test('parseActions handles JSON array of classes', function (): void {
        $manager = app()->make(EventManager::class);
        $ref = new ReflectionMethod($manager, 'parseActions');
        $ref->setAccessible(true);

        $result = $ref->invoke($manager, '["App\\Actions\\Foo", "App\\Actions\\Bar"]');

        expect($result)->toBe(['App\\Actions\\Foo', 'App\\Actions\\Bar']);
    });

    test('parseActions handles JSON object with class and params', function (): void {
        $manager = app()->make(EventManager::class);
        $ref = new ReflectionMethod($manager, 'parseActions');
        $ref->setAccessible(true);

        $result = $ref->invoke($manager, '{"class": "App\\Actions\\Foo", "params": {"url": "https://example.com"}}');

        expect($result)->toBe([
            ['class' => 'App\\Actions\\Foo', 'params' => ['url' => 'https://example.com']],
        ]);
    });

    test('parseActions handles JSON object with classes and shared params', function (): void {
        $manager = app()->make(EventManager::class);
        $ref = new ReflectionMethod($manager, 'parseActions');
        $ref->setAccessible(true);

        $result = $ref->invoke($manager, '{"classes": ["App\\Actions\\Foo", "App\\Actions\\Bar"], "params": {"url": "https://example.com"}}');

        expect($result)->toBe([
            ['class' => 'App\\Actions\\Foo', 'params' => ['url' => 'https://example.com']],
            ['class' => 'App\\Actions\\Bar', 'params' => ['url' => 'https://example.com']],
        ]);
    });

    test('parseActions returns empty array for empty string', function (): void {
        $manager = app()->make(EventManager::class);
        $ref = new ReflectionMethod($manager, 'parseActions');
        $ref->setAccessible(true);

        expect($ref->invoke($manager, ''))->toBe([]);
    });
});
