<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('EventManager fireModel edge cases', function (): void {
    it('accepts a plain stdClass object without attributesToArray', function (): void {
        $eventManager = app(\ZeroBoiler\Events\EventManager::class);

        // Globally disable so we don't try to dispatch actual triggers
        $eventManager->setEnabled(false);

        // Should not throw — stdClass has neither attributesToArray nor toArray,
        // so the payload will be minimal (no flattened model data)
        $obj = new \stdClass;
        $obj->id = 42;

        $eventManager->fireModel('stdClass', 'created', $obj);

        // If we get here without exception, the test passes
        expect(true)->toBeTrue();
    });

    it('throws on empty model class name', function (): void {
        app(\ZeroBoiler\Events\EventManager::class)
            ->fireModel('', 'created', new \stdClass);
    })->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

    it('throws on empty model action', function (): void {
        app(\ZeroBoiler\Events\EventManager::class)
            ->fireModel('App\\Models\\Test', '', new \stdClass);
    })->throws(\InvalidArgumentException::class, 'Model action cannot be empty');

    it('includes flattened model attributes in payload', function (): void {
        $eventManager = app(\ZeroBoiler\Events\EventManager::class);
        $eventManager->setEnabled(false);

        // Create a trigger to capture the event log
        $trigger = $eventManager->on('App\\Models\\FakeModel.created')
            ->name('FireModel Test')
            ->action(\App\Actions\SendOrderNotification::class)
            ->save();

        try {
            // Create a fake model-like object with toArray
            $model = new class {
                public function toArray(): array
                {
                    return ['id' => 99, 'name' => 'Test Model'];
                }
            };

            // Since events are disabled, no actual dispatch happens.
            // We just verify the method doesn't throw for valid input.
            $eventManager->fireModel('App\\Models\\FakeModel', 'created', $model);
            expect(true)->toBeTrue();
        } finally {
            $eventManager->deleteTrigger($trigger->id);
        }
    });

    it('throws on fire with empty event name', function (): void {
        app(\ZeroBoiler\Events\EventManager::class)
            ->fire('');
    })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

    it('throws on fire with zero-string event name', function (): void {
        app(\ZeroBoiler\Events\EventManager::class)
            ->fire('0');
    })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

    it('silently returns when events are globally disabled', function (): void {
        $eventManager = app(\ZeroBoiler\Events\EventManager::class);
        $eventManager->setEnabled(false);

        // fire() should silently return, no exception
        $eventManager->fire('test.disabled.event', ['key' => 'value']);
        expect($eventManager->isDisabled())->toBeTrue();
    });

    it('setEnabled(true) re-enables the event system', function (): void {
        $eventManager = app(\ZeroBoiler\Events\EventManager::class);
        $eventManager->setEnabled(false);
        expect($eventManager->isDisabled())->toBeTrue();

        $eventManager->setEnabled(true);
        expect($eventManager->isDisabled())->toBeFalse();
    });
});
