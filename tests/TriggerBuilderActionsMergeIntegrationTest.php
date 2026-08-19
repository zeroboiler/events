<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

describe('TriggerBuilder actions merge integration', function () {
    it('stores single action as plain class name', function () {
        $manager = $this->app->make(EventManager::class);
        $trigger = $manager->on('test.event')
            ->action('NullAction')
            ->save();

        // Single action without params should be stored as plain FQN string
        expect($trigger->action)->toBe('NullAction');
    });

    it('stores single action with params as JSON object', function () {
        $manager = $this->app->make(EventManager::class);
        $trigger = $manager->on('test.event')
            ->action('NullAction')
            ->actionParams(['url' => 'https://example.com'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray();
        expect($decoded['class'])->toBe('NullAction');
        expect($decoded['params']['url'])->toBe('https://example.com');
    });

    it('stores multiple actions as JSON array', function () {
        $manager = $this->app->make(EventManager::class);
        $trigger = $manager->on('test.event')
            ->actions(['NullAction', 'LogOrderEvent'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBe(['NullAction', 'LogOrderEvent']);
    });

    it('stores multiple actions with params using classes key', function () {
        $manager = $this->app->make(EventManager::class);
        $trigger = $manager->on('test.event')
            ->actions(['NullAction', 'LogOrderEvent'])
            ->actionParams(['webhook_url' => 'https://example.com'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray();
        expect($decoded['classes'])->toBe(['NullAction', 'LogOrderEvent']);
        expect($decoded['params']['webhook_url'])->toBe('https://example.com');
    });

    it('merges action() and actions() with deduplication', function () {
        $manager = $this->app->make(EventManager::class);
        $trigger = $manager->on('test.event')
            ->action('NullAction')
            ->actions(['LogOrderEvent', 'NullAction']) // NullAction duplicated
            ->save();

        $decoded = json_decode($trigger->action, true);
        // Should contain both, with NullAction appearing only once (prepended)
        expect($decoded)->toBe(['NullAction', 'LogOrderEvent']);
    });

    it('invalidates trigger cache after save', function () {
        $manager = $this->app->make(EventManager::class);
        // This should not throw — cache invalidation should work even if cache is empty
        $manager->on('test.cache.invalidate')
            ->action('NullAction')
            ->save();

        expect(true)->toBeTrue(); // If we reach here, no exception was thrown
    });
});
