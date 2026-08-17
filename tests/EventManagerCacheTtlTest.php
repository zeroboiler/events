<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;


beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Cache::forget('zeroboiler:events:enabled_wildcard_triggers');
});

test('wildcard trigger cache is populated after firing', function (): void {
    Trigger::factory()->create([
        'event' => 'order.*',
        'action' => 'ZeroBoiler\Events\Tests\Actions\LogOrderEvent',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 1]);

    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();
});

test('invalidateTriggerCache clears wildcard trigger cache', function (): void {
    Trigger::factory()->create([
        'event' => 'order.*',
        'action' => 'ZeroBoiler\Events\Tests\Actions\LogOrderEvent',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Fire to populate cache
    EventManagerFacade::fire('order.placed', ['order_id' => 1]);
    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

    // Invalidate
    EventManagerFacade::invalidateTriggerCache();
    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
});

test('configurable wildcard_cache_ttl is respected', function (): void {
    // Override config with custom TTL
    config(['events.wildcard_cache_ttl' => 60]);

    Trigger::factory()->create([
        'event' => 'order.*',
        'action' => 'ZeroBoiler\Events\Tests\Actions\LogOrderEvent',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::fire('order.placed', ['order_id' => 1]);

    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();
});
