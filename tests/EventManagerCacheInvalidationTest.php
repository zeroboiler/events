<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestCase;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

uses(TestCase::class);

beforeEach(function (): void {
    Trigger::query()->delete();
    Cache::forget('zeroboiler:events:enabled_wildcard_triggers');
});

test('cache invalidation on trigger create via builder', function (): void {
    // Prime the cache
    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
    Cache::put($cacheKey, collect(), 300);
    expect(Cache::get($cacheKey))->not->toBeNull();

    // Create a wildcard trigger via builder
    EventManagerFacade::on('order.*')
        ->action(SendOrderNotification::class)
        ->save();

    // Cache should be invalidated
    expect(Cache::get($cacheKey))->toBeNull();
});

test('cache invalidation on trigger enable', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'cache.test.*',
        'action' => SendOrderNotification::class,
        'enabled' => false,
    ]);

    // Prime cache
    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
    Cache::put($cacheKey, collect(), 300);

    EventManagerFacade::enable($trigger->id);

    expect(Cache::get($cacheKey))->toBeNull();
});

test('cache invalidation on trigger disable', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'cache.test.*',
        'action' => SendOrderNotification::class,
        'enabled' => true,
    ]);

    // Prime cache
    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
    Cache::put($cacheKey, collect(), 300);

    EventManagerFacade::disable($trigger->id);

    expect(Cache::get($cacheKey))->toBeNull();
});

test('cache invalidation on trigger delete', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'cache.test.*',
        'action' => SendOrderNotification::class,
    ]);

    // Prime cache
    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
    Cache::put($cacheKey, collect(), 300);

    $result = EventManagerFacade::deleteTrigger($trigger->id);

    expect($result)->toBeTrue();
    expect(Cache::get($cacheKey))->toBeNull();
});

test('multiple consecutive cache invalidations do not error', function (): void {
    EventManagerFacade::invalidateTriggerCache();
    EventManagerFacade::invalidateTriggerCache();
    EventManagerFacade::invalidateTriggerCache();

    expect(true)->toBeTrue();
});

test('cache is populated after firing wildcard event', function (): void {
    Trigger::factory()->create([
        'event' => 'pop.cache.*',
        'action' => SendOrderNotification::class,
        'enabled' => true,
    ]);

    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';

    // Cache should be empty before fire
    Cache::forget($cacheKey);

    $manager = app(EventManager::class);
    $manager->fire('pop.cache.something');

    // Cache should now be populated
    expect(Cache::get($cacheKey))->not->toBeNull();
});

test('non-wildcard trigger creation still invalidates cache', function (): void {
    // Prime cache
    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
    Cache::put($cacheKey, collect(), 300);

    // Create a non-wildcard trigger
    EventManagerFacade::on('exact.event')
        ->action(SendOrderNotification::class)
        ->save();

    // Cache should still be invalidated
    expect(Cache::get($cacheKey))->toBeNull();
});

test('enable on non-existent trigger does not invalidate cache', function (): void {
    // Prime cache
    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
    Cache::put($cacheKey, collect(), 300);

    $result = EventManagerFacade::enable('non-existent-id');

    // enable() returns false — cache should NOT be invalidated
    expect($result)->toBeFalse();
    expect(Cache::get($cacheKey))->not->toBeNull();
});

test('disable on non-existent trigger does not invalidate cache', function (): void {
    // Prime cache
    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
    Cache::put($cacheKey, collect(), 300);

    $result = EventManagerFacade::disable('non-existent-id');

    // disable() returns false — cache should NOT be invalidated
    expect($result)->toBeFalse();
    expect(Cache::get($cacheKey))->not->toBeNull();
});

test('delete on non-existent trigger does not invalidate cache', function (): void {
    // Prime cache
    $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
    Cache::put($cacheKey, collect(), 300);

    $result = EventManagerFacade::deleteTrigger('non-existent-id');

    // delete() returns false — cache should NOT be invalidated
    expect($result)->toBeFalse();
    expect(Cache::get($cacheKey))->not->toBeNull();
});
