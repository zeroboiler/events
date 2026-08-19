<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\Actions\NullAction;

/**
 * Extended edge-case tests for EventManager::fireModel().
 *
 * @see \ZeroBoiler\Events\EventManager::fireModel()
 *
 * @since 1.0.0
 */
final class FireModelEdgeCasesExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register a sync test trigger for model events
        $this->app->bind(NullAction::class, fn (): NullAction => new NullAction());

        Trigger::create([
            'name' => 'Model Created Trigger',
            'event' => 'App\\Models\\FakeModel.created',
            'action' => NullAction::class,
            'async' => false,
            'priority' => 0,
            'enabled' => true,
        ]);
    }

    public function test_fire_model_with_plain_object_no_array_methods(): void
    {
        $manager = self::$app->make(EventManager::class);

        // A plain stdClass has neither attributesToArray() nor toArray()
        $plainObject = new \stdClass();
        $plainObject->name = 'Test';

        // Should fire without error, with only metadata in payload
        $manager->fireModel('App\\Models\\FakeModel', 'created', $plainObject);

        $log = EventLog::first();
        expect($log)->not->toBeNull();
        expect($log->event)->toBe('App\\Models\\FakeModel.created');
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
        // Payload should contain metadata keys
        expect($log->payload)->toHaveKey('model');
        expect($log->payload)->toHaveKey('model_class');
        expect($log->payload)->toHaveKey('action');
        expect($log->payload['model_class'])->toBe('App\\Models\\FakeModel');
        expect($log->payload['action'])->toBe('created');
    }

    public function test_fire_model_with_object_having_toArray_only(): void
    {
        $manager = self::$app->make(EventManager::class);

        // Register trigger
        Trigger::create([
            'name' => 'ToArray Model Trigger',
            'event' => 'App\\Models\\ToArrayModel.updated',
            'action' => NullAction::class,
            'async' => false,
            'priority' => 0,
            'enabled' => true,
        ]);

        $model = new class {
            public function toArray(): array
            {
                return ['id' => '123', 'status' => 'active'];
            }
        };

        $manager->fireModel('App\\Models\\ToArrayModel', 'updated', $model);

        $log = EventLog::where('event', 'App\\Models\\ToArrayModel.updated')->first();
        expect($log)->not->toBeNull();
        expect($log->payload)->toHaveKey('id');
        expect($log->payload)->toHaveKey('status');
        expect($log->payload['id'])->toBe('123');
        expect($log->payload['status'])->toBe('active');
    }

    public function test_fire_model_with_empty_model_class_throws(): void
    {
        $manager = self::$app->make(EventManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model class name cannot be empty');

        $manager->fireModel('', 'created', new \stdClass());
    }

    public function test_fire_model_with_empty_action_throws(): void
    {
        $manager = self::$app->make(EventManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model action cannot be empty');

        $manager->fireModel('App\\Models\\FakeModel', '', new \stdClass());
    }

    public function test_fire_model_with_zero_string_class_throws(): void
    {
        $manager = self::$app->make(EventManager::class);

        $this->expectException(\InvalidArgumentException::class);

        $manager->fireModel('0', 'created', new \stdClass());
    }

    public function test_fire_model_with_zero_string_action_throws(): void
    {
        $manager = self::$app->make(EventManager::class);

        $this->expectException(\InvalidArgumentException::class);

        $manager->fireModel('App\\Models\\FakeModel', '0', new \stdClass());
    }
}
