<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
});

test('getTrigger returns trigger when found', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'enabled' => true,
    ]);

    $found = EventManagerFacade::getTrigger($trigger->id);

    expect($found)->not->toBeNull()
        ->toBeInstanceOf(Trigger::class)
        ->and($found->id)->toBe($trigger->id)
        ->and($found->event)->toBe('order.placed')
        ->and($found->name)->toBe($trigger->name);
});

test('getTrigger returns null when not found', function (): void {
    $found = EventManagerFacade::getTrigger('00000000-0000-0000-0000-000000000000');

    expect($found)->toBeNull();
});

test('getTrigger returns null for empty string id', function (): void {
    $found = EventManagerFacade::getTrigger('');

    expect($found)->toBeNull();
});

test('getTrigger respects soft deletes', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
    ]);

    $trigger->delete();

    expect(Trigger::find($trigger->id))->toBeNull()
        ->and(EventManagerFacade::getTrigger($trigger->id))->toBeNull();
});

test('deleteTrigger removes trigger and invalidates cache', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
    ]);

    $triggerId = $trigger->id;

    // Confirm trigger exists
    expect(Trigger::find($triggerId))->not->toBeNull();

    $result = EventManagerFacade::deleteTrigger($triggerId);

    expect($result)->toBeTrue()
        ->and(Trigger::find($triggerId))->toBeNull();
});

test('deleteTrigger returns false for non-existent trigger', function (): void {
    $result = EventManagerFacade::deleteTrigger('00000000-0000-0000-0000-000000000000');

    expect($result)->toBeFalse();
});

test('deleteTrigger returns false for empty string id', function (): void {
    $result = EventManagerFacade::deleteTrigger('');

    expect($result)->toBeFalse();
});

test('deleteTrigger only deletes the target trigger', function (): void {
    $triggerA = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
    ]);

    $triggerB = Trigger::factory()->create([
        'event' => 'order.shipped',
        'action' => SendOrderNotification::class,
    ]);

    EventManagerFacade::deleteTrigger($triggerA->id);

    expect(Trigger::find($triggerA->id))->toBeNull()
        ->and(Trigger::find($triggerB->id))->not->toBeNull();
});

test('deleteTrigger with soft delete keeps record queryable via withTrashed', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
    ]);

    $triggerId = $trigger->id;

    EventManagerFacade::deleteTrigger($triggerId);

    // Normal find should not find it
    expect(Trigger::find($triggerId))->toBeNull();

    // With trashed should find it
    expect(Trigger::withTrashed()->find($triggerId))->not->toBeNull();
});

test('deleteTrigger followed by fire does not dispatch deleted trigger', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventManagerFacade::deleteTrigger($trigger->id);

    EventManagerFacade::fire('order.placed', ['order_id' => 123]);

    expect(EventLog::count())->toBe(0);
});

test('getTrigger and deleteTrigger work together', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
    ]);

    // Get it
    $found = EventManagerFacade::getTrigger($trigger->id);
    expect($found)->not->toBeNull();

    // Delete it
    EventManagerFacade::deleteTrigger($trigger->id);

    // Get it again — should be null
    $foundAgain = EventManagerFacade::getTrigger($trigger->id);
    expect($foundAgain)->toBeNull();
});
