<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\TriggerBuilder;

/**
 * Tests for EventManager::register() alias and other uncovered methods.
 */
describe('EventManager register alias', function (): void {
    it('register() returns same type as on()', function (): void {
        $em = $this->app->make(EventManager::class);

        $builder = $em->register('test.event');

        expect($builder)->toBeInstanceOf(TriggerBuilder::class);
    });

    it('register() creates same builder as on()', function (): void {
        $em = $this->app->make(EventManager::class);

        $onBuilder = $em->on('test.event');
        $registerBuilder = $em->register('test.event');

        expect($onBuilder)->toBeInstanceOf(TriggerBuilder::class);
        expect($registerBuilder)->toBeInstanceOf(TriggerBuilder::class);
        expect($onBuilder)->not->toBe($registerBuilder); // Should be different instances (transient)
    });

    it('fire() with empty event name matches no triggers', function (): void {
        $em = $this->app->make(EventManager::class);

        // Fire empty event — should not throw, just find no triggers
        $em->fire('');

        expect(true)->toBeTrue(); // No exception = pass
    });

    it('fire() with empty payload dispatches matching triggers', function (): void {
        $em = $this->app->make(EventManager::class);

        $trigger = $em->on('test.fire.empty')
            ->name('Test Empty Payload')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        $em->fire('test.fire.empty');

        // Should have created an event log
        $logs = \ZeroBoiler\Events\Models\EventLog::where('trigger_id', $trigger->id)->get();
        expect($logs->count())->toBe(1);
        expect($logs->first()->status)->toBe('completed');
    });

    it('disable() returns false for non-existent trigger', function (): void {
        $em = $this->app->make(EventManager::class);

        $result = $em->disable('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    it('enable() returns false for non-existent trigger', function (): void {
        $em = $this->app->make(EventManager::class);

        $result = $em->enable('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    it('invalidateTriggerCache() can be called multiple times safely', function (): void {
        $em = $this->app->make(EventManager::class);

        // Should not throw
        $em->invalidateTriggerCache();
        $em->invalidateTriggerCache();
        $em->invalidateTriggerCache();

        expect(true)->toBeTrue();
    });
});
