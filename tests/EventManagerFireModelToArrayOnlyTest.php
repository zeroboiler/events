<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Tests for EventManager::fireModel() with models that have only toArray()
 * (without attributesToArray).
 */
describe('EventManager::fireModel — toArray-only model', function (): void {
    it('flattens attributes from toArray() when attributesToArray is absent', function (): void {
        $trigger = Trigger::factory()
            ->forEvent('SimpleModel.updated')
            ->enabled()
            ->sync()
            ->withAction(\ZeroBoiler\Events\Tests\Actions\NullAction::class)
            ->create();

        $model = new class {
            /**
             * @return array<string, mixed>
             */
            public function toArray(): array
            {
                return [
                    'name' => 'TestItem',
                    'status' => 'active',
                ];
            }
        };

        EventManager::fireModel(
            'SimpleModel',
            'updated',
            $model,
        );

        $this->assertDatabaseHas('event_logs', [
            'event' => 'SimpleModel.updated',
            'trigger_id' => $trigger->id,
            'status' => 'completed',
        ]);

        // Verify the payload contains flattened model attributes
        $log = \ZeroBoiler\Events\Models\EventLog::where('trigger_id', $trigger->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->payload)->toBeArray()
            ->and($log->payload['name'])->toBe('TestItem')
            ->and($log->payload['status'])->toBe('active')
            ->and($log->payload['model_class'])->toBe('SimpleModel')
            ->and($log->payload['action'])->toBe('updated')
            ->and($log->payload['model'])->toBeInstanceOf(get_class($model));
    });

    it('prefers attributesToArray over toArray when both exist', function (): void {
        $trigger = Trigger::factory()
            ->forEvent('DualModel.created')
            ->enabled()
            ->sync()
            ->withAction(\ZeroBoiler\Events\Tests\Actions\NullAction::class)
            ->create();

        $model = new class {
            /**
             * @return array<string, mixed>
             */
            public function attributesToArray(): array
            {
                return ['from' => 'attributesToArray'];
            }

            /**
             * @return array<string, mixed>
             */
            public function toArray(): array
            {
                return ['from' => 'toArray'];
            }
        };

        EventManager::fireModel(
            'DualModel',
            'created',
            $model,
        );

        $log = \ZeroBoiler\Events\Models\EventLog::where('trigger_id', $trigger->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->payload['from'])->toBe('attributesToArray');
    });

    it('includes only metadata keys when model has neither method', function (): void {
        $trigger = Trigger::factory()
            ->forEvent('PlainObject.deleted')
            ->enabled()
            ->sync()
            ->withAction(\ZeroBoiler\Events\Tests\Actions\NullAction::class)
            ->create();

        $model = new \stdClass();

        EventManager::fireModel(
            'PlainObject',
            'deleted',
            $model,
        );

        $log = \ZeroBoiler\Events\Models\EventLog::where('trigger_id', $trigger->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->payload)->toBeArray()
            ->and($log->payload['model_class'])->toBe('PlainObject')
            ->and($log->payload['action'])->toBe('deleted')
            ->and($log->payload['model'])->toBe($model)
            ->and(isset($log->payload['name']))->toBeFalse();
    });

    it('constructs event name as modelClass.action', function (): void {
        $trigger = Trigger::factory()
            ->forEvent('App\\Models\\Order.shipped')
            ->enabled()
            ->sync()
            ->withAction(\ZeroBoiler\Events\Tests\Actions\NullAction::class)
            ->create();

        $model = new class {
            /** @return array<string, mixed> */
            public function attributesToArray(): array
            {
                return ['id' => 42];
            }
        };

        EventManager::fireModel('App\\Models\\Order', 'shipped', $model);

        $log = \ZeroBoiler\Events\Models\EventLog::where('trigger_id', $trigger->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->event)->toBe('App\\Models\\Order.shipped');
    });
});
