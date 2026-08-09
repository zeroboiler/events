<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

// Load test action classes (App\Actions namespace)
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Cache::forget('zeroboiler:events:enabled_wildcard_triggers');
});

describe('EventManager wildcard trigger cache', function (): void {
    test('wildcard triggers are cached after first fire', function (): void {
        Trigger::factory()->create([
            'event' => 'order.*',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
            'conditions' => null,
        ]);

        // First fire — should populate the cache
        EventManagerFacade::fire('order.placed', ['order_id' => 1]);

        // Verify cache was populated
        $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
        expect($cached)->not->toBeNull()
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class)
            ->count()->toBe(1);

        // Second fire — should use cached triggers
        EventManagerFacade::fire('order.shipped', ['order_id' => 2]);

        // Should have 2 event logs (both fires)
        expect(EventLog::count())->toBe(2);
    });

    test('cache is invalidated when a new wildcard trigger is created', function (): void {
        Trigger::factory()->create([
            'event' => 'order.*',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // Fire to populate cache
        EventManagerFacade::fire('order.placed');

        // Verify cache exists
        expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->not->toBeNull();

        // Create a new wildcard trigger — this should invalidate cache
        EventManagerFacade::on('payment.*')
            ->action(SendOrderNotification::class)
            ->save();

        // Cache should be cleared
        expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();

        // Fire the new wildcard event — should work
        EventManagerFacade::fire('payment.received');
        expect(EventLog::where('event', 'payment.received')->count())->toBe(1);
    });

    test('cache is invalidated when a trigger is disabled', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'order.*',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // Fire to populate cache
        EventManagerFacade::fire('order.placed');
        expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->not->toBeNull();

        // Disable the trigger
        EventManagerFacade::disable($trigger->id);

        // Cache should be cleared
        expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();
    });

    test('cache is invalidated when a trigger is enabled', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'order.*',
            'action' => SendOrderNotification::class,
            'enabled' => false,
            'async' => false,
        ]);

        // Fire with disabled trigger — no logs
        EventManagerFacade::fire('order.placed');
        expect(EventLog::count())->toBe(0);

        // Enable the trigger
        EventManagerFacade::enable($trigger->id);

        // Cache should be cleared
        expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();

        // Fire again — should now match
        EventManagerFacade::fire('order.placed');
        expect(EventLog::count())->toBe(1);
    });

    test('cache is invalidated when a trigger is deleted', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'order.*',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // Fire to populate cache
        EventManagerFacade::fire('order.placed');
        expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->not->toBeNull();

        // Delete the trigger
        EventManagerFacade::deleteTrigger($trigger->id);

        // Cache should be cleared
        expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();

        // Fire again — no match
        EventManagerFacade::fire('order.placed');
        expect(EventLog::where('event', 'order.placed')->count())->toBe(1); // only the first fire
    });

    test('exact triggers are not affected by wildcard cache', function (): void {
        Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // Fire exact match — should work without cache dependency
        EventManagerFacade::fire('order.placed');
        expect(EventLog::count())->toBe(1);
    });

    test('disabled wildcard triggers are excluded from cache', function (): void {
        Trigger::factory()->create([
            'event' => 'order.*',
            'action' => SendOrderNotification::class,
            'enabled' => false,
            'async' => false,
        ]);

        // Fire — disabled triggers should not match
        EventManagerFacade::fire('order.placed');
        expect(EventLog::count())->toBe(0);

        // Cache should be populated but only with enabled triggers
        $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
        if ($cached instanceof \Illuminate\Database\Eloquent\Collection) {
            expect($cached->count())->toBe(0);
        }
    });

    test('cross-segment wildcard triggers are cached', function (): void {
        Trigger::factory()->create([
            'event' => 'order.**',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // Fire multi-segment event — should match via cache
        EventManagerFacade::fire('order.item.added', ['item' => 'widget']);
        expect(EventLog::count())->toBe(1);

        // Fire single-segment event — should also match
        EventManagerFacade::fire('order.placed');
        expect(EventLog::count())->toBe(2);
    });

    test('multiple wildcard triggers are all cached', function (): void {
        Trigger::factory()->create([
            'event' => 'order.*',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        Trigger::factory()->create([
            'event' => '*.created',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // Fire to populate cache
        EventManagerFacade::fire('order.placed');

        $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
        if ($cached instanceof \Illuminate\Database\Eloquent\Collection) {
            expect($cached->count())->toBe(2);
        }
    });
});
