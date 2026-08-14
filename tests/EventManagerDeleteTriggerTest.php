<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

describe('EventManager::deleteTrigger', function (): void {
    it('deletes an existing trigger and returns true', function (): void {
        $trigger = Trigger::factory()->create();

        $eventManager = app(EventManager::class);
        $result = $eventManager->deleteTrigger($trigger->id);

        expect($result)->toBeTrue();
        expect(Trigger::find($trigger->id))->toBeNull();
    });

    it('returns false for a non-existent trigger ID', function (): void {
        $eventManager = app(EventManager::class);
        $result = $eventManager->deleteTrigger('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    it('invalidates the wildcard trigger cache after deletion', function (): void {
        $trigger = Trigger::factory()->create(['event' => 'test.delete']);

        // Prime the wildcard trigger cache
        Cache::put('zeroboiler:events:enabled_wildcard_triggers', collect(), 300);

        $eventManager = app(EventManager::class);
        $eventManager->deleteTrigger($trigger->id);

        // Cache should be invalidated after deleteTrigger
        expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();
    });

    it('soft-deletes the trigger (does not hard delete)', function (): void {
        $trigger = Trigger::factory()->create();

        $eventManager = app(EventManager::class);
        $eventManager->deleteTrigger($trigger->id);

        // Should be soft-deleted, not hard-deleted
        expect(Trigger::withTrashed()->find($trigger->id))->not->toBeNull();
        expect(Trigger::onlyTrashed()->find($trigger->id))->not->toBeNull();
    });

    it('cascades to event logs via foreign key', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->create(['trigger_id' => $trigger->id]);

        $eventManager = app(EventManager::class);
        $eventManager->deleteTrigger($trigger->id);

        // Event logs should be cascade-deleted
        expect(EventLog::where('trigger_id', $trigger->id)->exists())->toBeFalse();
    });

    it('handles deletion of a trigger with wildcard event pattern', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'order.*',
            'enabled' => true,
        ]);

        $eventManager = app(EventManager::class);
        $result = $eventManager->deleteTrigger($trigger->id);

        expect($result)->toBeTrue();
        expect(Trigger::find($trigger->id))->toBeNull();
    });
});
