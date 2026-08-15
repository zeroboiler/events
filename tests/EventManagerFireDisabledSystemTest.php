<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

describe('EventManager fire() with globally disabled system', function (): void {
    test('fire() silently returns when system is disabled via config', function (): void {
        $manager = app(EventManager::class);

        // Create a trigger that would normally match
        $trigger = Trigger::factory()->create([
            'event' => 'test.event',
            'enabled' => true,
            'action' => json_encode('TestAction'),
            'conditions' => [],
        ]);

        // Disable the system
        Config::set('events.disabled', true);

        // Fire — should not dispatch anything
        $manager->fire('test.event', ['key' => 'value']);

        // No event logs should have been created
        expect(\ZeroBoiler\Events\Models\EventLog::count())->toBe(0);

        // Clean up
        Config::set('events.disabled', false);
        $trigger->delete();
    });

    test('fire() with multiple triggers does nothing when disabled', function (): void {
        $manager = app(EventManager::class);

        $trigger1 = Trigger::factory()->create([
            'event' => 'multi.test',
            'enabled' => true,
            'action' => json_encode('ActionOne'),
        ]);

        $trigger2 = Trigger::factory()->create([
            'event' => 'multi.test',
            'enabled' => true,
            'action' => json_encode('ActionTwo'),
        ]);

        Config::set('events.disabled', true);

        $manager->fire('multi.test', ['data' => 'test']);

        expect(\ZeroBoiler\Events\Models\EventLog::count())->toBe(0);

        Config::set('events.disabled', false);
        $trigger1->delete();
        $trigger2->delete();
    });

    test('setEnabled(false) suppresses fire() calls', function (): void {
        $manager = app(EventManager::class);

        $trigger = Trigger::factory()->create([
            'event' => 'suppress.test',
            'enabled' => true,
            'action' => json_encode('SuppressAction'),
        ]);

        $manager->setEnabled(false);

        expect($manager->isDisabled())->toBeTrue();

        $manager->fire('suppress.test', ['key' => 'value']);

        expect(\ZeroBoiler\Events\Models\EventLog::count())->toBe(0);

        // Re-enable
        $manager->setEnabled(true);

        $trigger->delete();
    });

    test('isDisabled() returns correct state', function (): void {
        $manager = app(EventManager::class);

        expect($manager->isDisabled())->toBeFalse();

        $manager->setEnabled(false);
        expect($manager->isDisabled())->toBeTrue();

        $manager->setEnabled(true);
        expect($manager->isDisabled())->toBeFalse();
    });
});
