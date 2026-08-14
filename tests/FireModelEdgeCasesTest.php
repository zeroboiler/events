<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Database\Eloquent\Model;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Tests for EventManager::fireModel() with edge cases.
 */
final class FireModelEdgeCasesTest extends TestCase
{
    public function test_fire_model_empty_class_throws(): void
    {
        $eventManager = $this->app->make(EventManager::class);

        expect(fn (): mixed => $eventManager->fireModel('', 'created', new \stdClass()))
            ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
    }

    public function test_fire_model_zero_class_throws(): void
    {
        $eventManager = $this->app->make(EventManager::class);

        expect(fn (): mixed => $eventManager->fireModel('0', 'created', new \stdClass()))
            ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
    }

    public function test_fire_model_empty_action_throws(): void
    {
        $eventManager = $this->app->make(EventManager::class);

        expect(fn (): mixed => $eventManager->fireModel('App\\Models\\Order', '', new \stdClass()))
            ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
    }

    public function test_fire_model_zero_action_throws(): void
    {
        $eventManager = $this->app->make(EventManager::class);

        expect(fn (): mixed => $eventManager->fireModel('App\\Models\\Order', '0', new \stdClass()))
            ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
    }

    public function test_fire_model_constructs_correct_event_name(): void
    {
        $eventManager = $this->app->make(EventManager::class);

        $trigger = Trigger::factory()->enabled()->create([
            'event' => 'App\\Models\\Order.created',
            'action' => \App\Actions\LogOrderCreated::class,
            'async' => false,
        ]);

        // Create a simple Eloquent-like model
        $model = new class extends Model
        {
            protected $table = 'orders';

            public function attributesToArray(): array
            {
                return [
                    'id' => 1,
                    'status' => 'pending',
                    'total' => 99.99,
                ];
            }
        };

        $eventManager->fireModel('App\\Models\\Order', 'created', $model);

        $this->assertDatabaseHas('event_logs', [
            'trigger_id' => $trigger->id,
            'event' => 'App\\Models\\Order.created',
            'status' => EventLog::STATUS_COMPLETED,
        ]);
    }

    public function test_fire_model_includes_flattened_attributes_in_payload(): void
    {
        $eventManager = $this->app->make(EventManager::class);

        Trigger::factory()->enabled()->create([
            'event' => 'App\\Models\\Product.updated',
            'action' => \App\Actions\SendOrderNotification::class,
            'async' => false,
        ]);

        $model = new class extends Model
        {
            protected $table = 'products';

            public function attributesToArray(): array
            {
                return [
                    'id' => 42,
                    'name' => 'Test Product',
                    'price' => 25.00,
                ];
            }
        };

        $eventManager->fireModel('App\\Models\\Product', 'updated', $model);

        $log = EventLog::where('event', 'App\\Models\\Product.updated')->first();
        expect($log)->not->toBeNull();

        $payload = $log->payload;
        // Flattened attributes should be accessible at root level
        expect($payload)->toHaveKey('id');
        expect($payload)->toHaveKey('name');
        expect($payload)->toHaveKey('price');
        expect($payload)->toHaveKey('model');
        expect($payload)->toHaveKey('model_class');
        expect($payload)->toHaveKey('action');
        expect($payload['model_class'])->toBe('App\\Models\\Product');
        expect($payload['action'])->toBe('updated');
    }

    public function test_fire_model_with_generic_object_uses_to_array(): void
    {
        $eventManager = $this->app->make(EventManager::class);

        Trigger::factory()->enabled()->create([
            'event' => 'stdClass.saved',
            'action' => \App\Actions\LogOrderEvent::class,
            'async' => false,
        ]);

        $model = new class
        {
            public function toArray(): array
            {
                return ['key' => 'value', 'count' => 5];
            }
        };

        $eventManager->fireModel('stdClass', 'saved', $model);

        $log = EventLog::where('event', 'stdClass.saved')->first();
        expect($log)->not->toBeNull();
        expect($log->payload['key'])->toBe('value');
    }
}
