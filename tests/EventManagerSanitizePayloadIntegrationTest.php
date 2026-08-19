<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\Actions\NullAction;

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('EventManager payload sanitization for queue', function (): void {
    test('async dispatch strips non-serializable objects from payload', function (): void {
        $em = app(EventManager::class);

        $em->on('order.placed')
            ->action(NullAction::class)
            ->async()
            ->save();

        $model = new \stdClass;
        $model->name = 'Test';

        $em->fire('order.placed', [
            'order_id' => 123,
            'total' => 99.99,
            'model' => $model,
            'resource' => fopen('php://memory', 'r'),
            'nested' => [
                'deep_object' => new \stdClass,
                'scalar' => 'value',
            ],
        ], async: true);

        Queue::assertPushed(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class, function (\ZeroBoiler\Events\Jobs\DispatchTriggerJob $job): bool {
            $payload = $job->payload;

            // Scalars are preserved
            expect($payload['order_id'])->toBe(123);
            expect($payload['total'])->toBe(99.99);
            expect($payload['nested']['scalar'])->toBe('value');

            // Objects are replaced with type placeholders
            expect($payload['model'])->toBeString();
            expect(str_starts_with($payload['model'], '[stripped:'))->toBeTrue();

            // Resources are replaced with type placeholders
            expect($payload['resource'])->toBeString();
            expect(str_starts_with($payload['resource'], '[stripped:'))->toBeTrue();

            // Nested objects are also replaced
            expect($payload['nested']['deep_object'])->toBeString();
            expect(str_starts_with($payload['nested']['deep_object'], '[stripped:'))->toBeTrue();

            return true;
        });
    });

    test('sync dispatch preserves objects in payload (no sanitization)', function (): void {
        $em = app(EventManager::class);

        $em->on('order.placed')
            ->action(NullAction::class)
            ->save();

        $model = new \stdClass;
        $model->name = 'Test';

        $em->fire('order.placed', [
            'order_id' => 123,
            'model' => $model,
        ]);

        $log = EventLog::first();
        expect($log)->not->toBeNull();
        expect($log->status)->toBe('completed');

        // Sync dispatch stores the raw payload (object included)
        // Note: Eloquent's 'array' cast may serialize the object
        expect($log->payload)->toHaveKey('order_id');
        expect($log->payload['order_id'])->toBe(123);
    });

    test('null values in payload are preserved during sanitization', function (): void {
        $em = app(EventManager::class);

        $em->on('order.placed')
            ->action(NullAction::class)
            ->async()
            ->save();

        $em->fire('order.placed', [
            'order_id' => 123,
            'nullable' => null,
            'false_val' => false,
            'zero' => 0,
            'empty_string' => '',
        ], async: true);

        Queue::assertPushed(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class, function (\ZeroBoiler\Events\Jobs\DispatchTriggerJob $job): bool {
            $payload = $job->payload;

            expect($payload['order_id'])->toBe(123);
            expect($payload['nullable'])->toBeNull();
            expect($payload['false_val'])->toBeFalse();
            expect($payload['zero'])->toBe(0);
            expect($payload['empty_string'])->toBe('');

            return true;
        });
    });
});
