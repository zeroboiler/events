<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestActions;

test('fireModel with plain object without attributesToArray or toArray fires with metadata only', function (): void {
    $trigger = Trigger::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Test Trigger',
        'event' => 'stdClass.updated',
        'action' => TestActions::class,
        'conditions' => [],
        'async' => false,
        'priority' => 0,
        'enabled' => true,
    ]);

    TestActions::$lastPayload = null;

    $plainObject = new stdClass();
    $plainObject->data = 'test';

    $eventManager = app(EventManager::class);
    $eventManager->fireModel(stdClass::class, 'updated', $plainObject);

    expect(TestActions::$lastPayload)
        ->not->toBeNull()
        ->toHaveKey('model')
        ->toHaveKey('model_class')
        ->toHaveKey('action');

    expect(TestActions::$lastPayload['model_class'])->toBe(stdClass::class);
    expect(TestActions::$lastPayload['action'])->toBe('updated');
    expect(TestActions::$lastPayload['model'])->toBe($plainObject);
});

test('fireModel with empty model class throws InvalidArgumentException', function (): void {
    $eventManager = app(EventManager::class);

    expect(fn (): mixed => $eventManager->fireModel('', 'created', new stdClass()))
        ->toThrow(InvalidArgumentException::class, 'Model class name cannot be empty.');
});

test('fireModel with zero-string model class throws InvalidArgumentException', function (): void {
    $eventManager = app(EventManager::class);

    expect(fn (): mixed => $eventManager->fireModel('0', 'created', new stdClass()))
        ->toThrow(InvalidArgumentException::class, 'Model class name cannot be empty.');
});

test('fireModel with empty action throws InvalidArgumentException', function (): void {
    $eventManager = app(EventManager::class);

    expect(fn (): mixed => $eventManager->fireModel('App\\Models\\Order', '', new stdClass()))
        ->toThrow(InvalidArgumentException::class, 'Model action cannot be empty.');
});

test('fireModel with zero-string action throws InvalidArgumentException', function (): void {
    $eventManager = app(EventManager::class);

    expect(fn (): mixed => $eventManager->fireModel('App\\Models\\Order', '0', new stdClass()))
        ->toThrow(InvalidArgumentException::class, 'Model action cannot be empty.');
});

test('fireModel respects global disable', function (): void {
    $eventManager = app(EventManager::class);
    $eventManager->setEnabled(false);

    TestActions::$lastPayload = null;

    $trigger = Trigger::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Disabled Test',
        'event' => 'stdClass.deleted',
        'action' => TestActions::class,
        'conditions' => [],
        'async' => false,
        'priority' => 0,
        'enabled' => true,
    ]);

    $eventManager->fireModel(stdClass::class, 'deleted', new stdClass());

    expect(TestActions::$lastPayload)->toBeNull();

    $eventManager->setEnabled(true);
});

test('fireModel preserves original attributes when toArray returns nested data', function (): void {
    $trigger = Trigger::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Nested Data Trigger',
        'event' => 'TestModel.created',
        'action' => TestActions::class,
        'conditions' => ['status' => 'active'],
        'async' => false,
        'priority' => 0,
        'enabled' => true,
    ]);

    TestActions::$lastPayload = null;

    $model = new class {
        public function attributesToArray(): array
        {
            return [
                'status' => 'active',
                'nested' => ['key' => 'value'],
                'count' => 42,
            ];
        }
    };

    $eventManager = app(EventManager::class);
    $eventManager->fireModel('TestModel', 'created', $model);

    expect(TestActions::$lastPayload)->not->toBeNull();
    expect(TestActions::$lastPayload['status'])->toBe('active');
    expect(TestActions::$lastPayload['nested'])->toBe(['key' => 'value']);
    expect(TestActions::$lastPayload['count'])->toBe(42);
    expect(TestActions::$lastPayload['model_class'])->toBe('TestModel');
    expect(TestActions::$lastPayload['action'])->toBe('created');
});
