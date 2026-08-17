<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('EventManager::parseActions — action string parsing', function (): void {
    test('parses simple class name string', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'parse.simple',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('parse.simple', ['test' => true]);

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('parses JSON array of class names', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'parse.json.array',
            'action' => json_encode([SendOrderNotification::class, LogOrderEvent::class]),
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('parse.json.array', ['test' => true]);

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('parses JSON object with class + params', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'parse.json.object',
            'action' => json_encode([
                'class' => SendOrderNotification::class,
                'params' => ['url' => 'https://example.com/hook'],
            ]),
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('parse.json.object', ['test' => true]);

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('parses classes + params format (multi-action with shared params)', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'parse.classes.params',
            'action' => json_encode([
                'classes' => [SendOrderNotification::class, LogOrderEvent::class],
                'params' => ['topic' => 'orders'],
            ]),
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('parse.classes.params', ['test' => true]);

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('parses JSON array of objects with class + params', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'parse.array.objects',
            'action' => json_encode([
                ['class' => SendOrderNotification::class, 'params' => ['priority' => 1]],
                ['class' => LogOrderEvent::class, 'params' => ['priority' => 2]],
            ]),
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('parse.array.objects', ['test' => true]);

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('handles action that throws an exception during execution', function (): void {
        Trigger::factory()->create([
            'event' => 'parse.error',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // This should still create an event log even if action throws
        EventManagerFacade::fire('parse.error', ['force_error' => true]);

        // Sync trigger that throws should still log the failure
        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_FAILED);
    });
});
