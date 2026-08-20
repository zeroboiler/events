<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ReflectionMethod;
use ZeroBoiler\Events\EventManager;

uses(TestCase::class);

describe('EventManager::parseActions — classes format', function (): void {
    test('parses single class name string as-is', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $result = $method->invoke($manager, 'App\\Actions\\SendNotification');

        expect($result)->toBe(['App\\Actions\\SendNotification']);
    });

    test('parses JSON array of class names', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $result = $method->invoke($manager, '["App\\Actions\\Foo", "App\\Actions\\Bar"]');

        expect($result)->toBe(['App\\Actions\\Foo', 'App\\Actions\\Bar']);
    });

    test('parses classes+params object format into multiple entries', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $input = json_encode([
            'classes' => ['App\\Actions\\Foo', 'App\\Actions\\Bar'],
            'params' => ['url' => 'https://example.com'],
        ], \JSON_THROW_ON_ERROR);

        $result = $method->invoke($manager, $input);

        expect($result)->toHaveCount(2);
        expect($result[0])->toBe(['class' => 'App\\Actions\\Foo', 'params' => ['url' => 'https://example.com']]);
        expect($result[1])->toBe(['class' => 'App\\Actions\\Bar', 'params' => ['url' => 'https://example.com']]);
    });

    test('parses single class+params object into single entry', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $input = json_encode([
            'class' => 'App\\Actions\\Webhook',
            'params' => ['url' => 'https://example.com'],
        ], \JSON_THROW_ON_ERROR);

        $result = $method->invoke($manager, $input);

        expect($result)->toHaveCount(1);
        expect($result[0])->toBe(['class' => 'App\\Actions\\Webhook', 'params' => ['url' => 'https://example.com']]);
    });

    test('parses JSON array of objects with class+params', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $input = json_encode([
            ['class' => 'App\\Actions\\Foo', 'params' => ['priority' => 1]],
            ['class' => 'App\\Actions\\Bar', 'params' => ['priority' => 2]],
        ], \JSON_THROW_ON_ERROR);

        $result = $method->invoke($manager, $input);

        expect($result)->toHaveCount(2);
        expect($result[0])->toBe(['class' => 'App\\Actions\\Foo', 'params' => ['priority' => 1]]);
        expect($result[1])->toBe(['class' => 'App\\Actions\\Bar', 'params' => ['priority' => 2]]);
    });

    test('returns empty array for empty string', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $result = $method->invoke($manager, '');

        expect($result)->toBe([]);
    });

    test('returns empty array for whitespace-only string', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $result = $method->invoke($manager, '   ');

        expect($result)->toBe([]);
    });

    test('handles classes key with empty params gracefully', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $input = json_encode([
            'classes' => ['App\\Actions\\Foo'],
        ], \JSON_THROW_ON_ERROR);

        $result = $method->invoke($manager, $input);

        expect($result)->toHaveCount(1);
        expect($result[0])->toBe(['class' => 'App\\Actions\\Foo', 'params' => []]);
    });

    test('non-array JSON value falls back to single-entry array', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        // A JSON string that's not an array (e.g., a bare string like "hello")
        $result = $method->invoke($manager, 'NotJsonAtAll');

        expect($result)->toBe(['NotJsonAtAll']);
    });

    test('classes format with non-string entries normalizes to empty class', function (): void {
        $manager = $this->app->make(EventManager::class);
        $method = new ReflectionMethod($manager, 'parseActions');
        $method->setAccessible(true);

        $input = json_encode([
            'classes' => ['App\\Actions\\Foo', 123, null],
            'params' => [],
        ], \JSON_THROW_ON_ERROR);

        $result = $method->invoke($manager, $input);

        expect($result)->toHaveCount(3);
        expect($result[0])->toBe(['class' => 'App\\Actions\\Foo', 'params' => []]);
        expect($result[1])->toBe(['class' => '', 'params' => []]);
        expect($result[2])->toBe(['class' => '', 'params' => []]);
    });
});
