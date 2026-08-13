<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

describe('EventManager public API completeness', function (): void {
    it('does not have a fake() method (as documented)', function (): void {
        $app = app();
        $manager = $app->make(EventManager::class);

        expect(method_exists($manager, 'fake'))->toBeFalse();
    });

    it('has setEnabled() method for test disabling', function (): void {
        $app = app();
        $manager = $app->make(EventManager::class);

        expect(method_exists($manager, 'setEnabled'))->toBeTrue();
        expect(method_exists($manager, 'isDisabled'))->toBeTrue();
    });

    it('setEnabled(false) prevents fire() from dispatching', function (): void {
        $app = app();
        $manager = $app->make(EventManager::class);

        $manager->setEnabled(false);
        expect($manager->isDisabled())->toBeTrue();

        // fire() should silently return when disabled
        $manager->fire('test.event', ['key' => 'value']);

        // No event logs should have been created
        $logs = \ZeroBoiler\Events\Models\EventLog::all();
        expect($logs)->toHaveCount(0);

        // Re-enable
        $manager->setEnabled(true);
        expect($manager->isDisabled())->toBeFalse();
    });

    it('facade proxy references correct underlying class', function (): void {
        expect(EventManagerFacade::getFacadeAccessor())
            ->toBe(EventManager::class);
    });

    it('all documented facade methods exist on EventManager', function (): void {
        $app = app();
        $manager = $app->make(EventManager::class);

        $documentedMethods = [
            'on',
            'register',
            'fire',
            'fireModel',
            'enable',
            'disable',
            'invalidateTriggerCache',
            'isDisabled',
            'setEnabled',
            'listTriggers',
            'getTrigger',
            'deleteTrigger',
            'subscribe',
            'unsubscribe',
            'listSubscriptions',
            'getSubscription',
            'subscribeWebhook',
            'getEventHistory',
            'getStats',
            'purgeLogs',
            'getStalePendingLogs',
            'deactivateExceededSubscriptions',
            'executeTrigger',
            'registerScheduler',
        ];

        foreach ($documentedMethods as $method) {
            expect(method_exists($manager, $method))
                ->toBeTrue("EventManager::{$method}() is documented in the facade but does not exist");
        }
    });
});
