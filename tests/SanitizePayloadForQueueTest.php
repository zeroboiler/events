<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ReflectionMethod;
use ZeroBoiler\Events\EventManager;

/**
 * Tests for EventManager::sanitizePayloadForQueue().
 *
 * Verifies that non-serializable values (objects, resources, closures)
 * are replaced with type placeholders, while scalars and nested arrays
 * are preserved.
 *
 * @covers \ZeroBoiler\Events\EventManager::sanitizePayloadForQueue
 *
 * @since 1.0.0
 */
final class SanitizePayloadForQueueTest extends TestCase
{
    /**
     * Invoke the protected sanitizePayloadForQueue method via reflection.
     *
     * @param  array<string, mixed>  $payload
     * @return array<mixed, mixed>
     */
    private function sanitizePayloadForQueue(array $payload): array
    {
        $manager = self::$app->make(EventManager::class);

        $method = new ReflectionMethod(EventManager::class, 'sanitizePayloadForQueue');
        $method->setAccessible(true);

        return $method->invoke($manager, $payload);
    }

    public function test_scalars_are_preserved(): void
    {
        $payload = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
        ];

        $result = $this->sanitizePayloadForQueue($payload);

        expect($result)->toBe([
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
        ]);
    }

    public function test_objects_are_stripped(): void
    {
        $payload = [
            'model' => new \stdClass(),
            'name' => 'test',
        ];

        $result = $this->sanitizePayloadForQueue($payload);

        expect($result['model'])->toBe('[stripped:stdClass]');
        expect($result['name'])->toBe('test');
    }

    public function test_closures_are_stripped(): void
    {
        $payload = [
            'callback' => fn (): string => 'secret',
            'key' => 'value',
        ];

        $result = $this->sanitizePayloadForQueue($payload);

        expect($result['callback'])->toBe('[stripped:Closure]');
        expect($result['key'])->toBe('value');
    }

    public function test_nested_arrays_are_recursively_sanitized(): void
    {
        $payload = [
            'nested' => [
                'deep' => [
                    'object' => new \stdClass(),
                    'safe' => 'value',
                ],
            ],
        ];

        $result = $this->sanitizePayloadForQueue($payload);

        expect($result['nested']['deep']['object'])->toBe('[stripped:stdClass]');
        expect($result['nested']['deep']['safe'])->toBe('value');
    }

    public function test_empty_array_preserved(): void
    {
        $result = $this->sanitizePayloadForQueue([]);

        expect($result)->toBe([]);
    }

    public function test_empty_nested_arrays_preserved(): void
    {
        $payload = ['items' => []];

        $result = $this->sanitizePayloadForQueue($payload);

        expect($result)->toBe(['items' => []]);
    }

    public function test_multiple_objects_stripped_independently(): void
    {
        $payload = [
            'obj1' => new \stdClass(),
            'obj2' => new class {},
            'scalar' => 'ok',
        ];

        $result = $this->sanitizePayloadForQueue($payload);

        expect($result['obj1'])->toBe('[stripped:stdClass]');
        expect($result['obj2'])->toContain('[stripped:');
        expect($result['scalar'])->toBe('ok');
    }
}
