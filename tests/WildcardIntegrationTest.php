<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('Cross-segment wildcard matching (**)', function (): void {
    test('order.** matches order.placed.extra (multi-segment)', function (): void {
        Trigger::factory()->create([
            'event' => 'order.**',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('order.placed.extra', ['order_id' => 123]);

        expect(EventLog::count())->toBe(1)
            ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('order.** matches order.placed (single segment)', function (): void {
        Trigger::factory()->create([
            'event' => 'order.**',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('order.placed', ['order_id' => 123]);

        expect(EventLog::count())->toBe(1);
    });

    test('*.order.** matches user.order.created.extra', function (): void {
        Trigger::factory()->create([
            'event' => '*.order.**',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('user.order.created.extra', ['test' => true]);

        expect(EventLog::count())->toBe(1);
    });
});

describe('Catch-all wildcard (*)', function (): void {
    test('catch-all * matches multi-segment events', function (): void {
        Trigger::factory()->create([
            'event' => '*',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('deep.nested.event.here', ['test' => true]);

        expect(EventLog::count())->toBe(1);
    });

    test('catch-all * does not match empty event name', function (): void {
        Trigger::factory()->create([
            'event' => '*',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('', ['test' => true]);

        expect(EventLog::count())->toBe(0);
    });
});

describe('Multiple wildcards per pattern', function (): void {
    test('user.*.order.* matches user.123.order.created', function (): void {
        Trigger::factory()->create([
            'event' => 'user.*.order.*',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('user.123.order.created', ['test' => true]);

        expect(EventLog::count())->toBe(1);
    });

    test('*.order.* does NOT match order.placed (only 2 segments)', function (): void {
        Trigger::factory()->create([
            'event' => '*.order.*',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('order.placed', ['test' => true]);

        expect(EventLog::count())->toBe(0);
    });
});

describe('Fire event with no matching triggers', function (): void {
    test('fire event with no triggers creates no logs', function (): void {
        EventManagerFacade::fire('nonexistent.event', ['data' => true]);

        expect(EventLog::count())->toBe(0);
    });

    test('fire event with only disabled triggers creates no logs', function (): void {
        Trigger::factory()->create([
            'event' => 'disabled.event',
            'action' => SendOrderNotification::class,
            'enabled' => false,
            'async' => false,
        ]);

        EventManagerFacade::fire('disabled.event', ['test' => true]);

        expect(EventLog::count())->toBe(0);
    });
});

describe('Async fire event with wildcard trigger', function (): void {
    test('wildcard trigger with async queues job', function (): void {
        Trigger::factory()->create([
            'event' => 'payment.**',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => true,
        ]);

        EventManagerFacade::fire('payment.received.refund', ['amount' => 50]);

        Queue::assertPushed(DispatchTriggerJob::class);
    });
});
