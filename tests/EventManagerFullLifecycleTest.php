<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\Subscription;

describe('EventManager full lifecycle integration', function (): void {
    it('completes a full trigger lifecycle: register → fire → log → delete', function (): void {
        // Register
        $trigger = EventManager::on('lifecycle.test')
            ->name('Lifecycle Test Trigger')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->async(false)
            ->priority(5)
            ->save();

        expect($trigger)->toBeInstanceOf(Trigger::class);
        expect($trigger->id)->not->toBeEmpty();
        expect($trigger->event)->toBe('lifecycle.test');
        expect($trigger->enabled)->toBeTrue();

        // Fire
        EventManager::fire('lifecycle.test', ['key' => 'value']);

        // Verify log created
        $logs = EventLog::where('event', 'lifecycle.test')->get();
        expect($logs)->not->toBeEmpty();
        expect($logs->first()->status)->toBe(EventLog::STATUS_COMPLETED);

        // List
        $found = EventManager::listTriggers('lifecycle.test');
        expect($found)->not->toBeEmpty();

        // Get
        $got = EventManager::getTrigger($trigger->id);
        expect($got)->not->toBeNull();
        expect($got->id)->toBe($trigger->id);

        // Delete
        $deleted = EventManager::deleteTrigger($trigger->id);
        expect($deleted)->toBeTrue();

        // Verify gone
        expect(Trigger::find($trigger->id))->toBeNull();
    });

    it('supports enable/disable lifecycle', function (): void {
        $trigger = EventManager::on('toggle.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        expect($trigger->enabled)->toBeTrue();

        // Disable
        $result = EventManager::disable($trigger->id);
        expect($result)->toBeTrue();

        $trigger->refresh();
        expect($trigger->enabled)->toBeFalse();

        // Enable
        $result = EventManager::enable($trigger->id);
        expect($result)->toBeTrue();

        $trigger->refresh();
        expect($trigger->enabled)->toBeTrue();

        EventManager::deleteTrigger($trigger->id);
    });

    it('supports global disable/enable', function (): void {
        $trigger = EventManager::on('global.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        EventManager::setEnabled(false);
        expect(EventManager::isDisabled())->toBeTrue();

        // Fire should be silently ignored
        EventManager::fire('global.test', ['key' => 'value']);
        $logs = EventLog::where('event', 'global.test')->get();
        expect($logs)->toBeEmpty();

        // Re-enable
        EventManager::setEnabled(true);
        expect(EventManager::isDisabled())->toBeFalse();

        EventManager::fire('global.test', ['key' => 'value']);
        $logs = EventLog::where('event', 'global.test')->get();
        expect($logs)->not->toBeEmpty();

        EventManager::deleteTrigger($trigger->id);
    });

    it('supports wildcard trigger matching with cache invalidation', function (): void {
        // Register exact trigger
        $exact = EventManager::on('order.placed')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->priority(10)
            ->save();

        // Register wildcard trigger
        $wildcard = EventManager::on('order.*')
            ->action(\ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class)
            ->name('Order Wildcard')
            ->priority(5)
            ->save();

        EventManager::fire('order.placed', ['test' => true]);

        // Both triggers should fire
        $logs = EventLog::where('event', 'order.placed')->get();
        expect($logs->count())->toBeGreaterThanOrEqual(2);

        // Wildcard trigger fires for different events
        EventManager::fire('order.shipped', ['test' => true]);

        $logs = EventLog::where('event', 'order.shipped')->get();
        expect($logs)->not->toBeEmpty();

        EventManager::deleteTrigger($exact->id);
        EventManager::deleteTrigger($wildcard->id);
    });

    it('supports condition-based filtering', function (): void {
        $trigger = EventManager::on('conditional.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->when(['amount' => ['>', 100]])
            ->save();

        // Should fire — amount > 100
        EventManager::fire('conditional.test', ['amount' => 150]);
        $firedLogs = EventLog::where('event', 'conditional.test')->get();
        expect($firedLogs)->not->toBeEmpty();

        // Should NOT fire — amount <= 100
        EventManager::fire('conditional.test', ['amount' => 50]);
        $allLogs = EventLog::where('event', 'conditional.test')->get();
        expect($allLogs->count())->toBe($firedLogs->count()); // No new log

        EventManager::deleteTrigger($trigger->id);
    });

    it('supports full subscription lifecycle', function (): void {
        // Subscribe
        $subscription = EventManager::subscribe('sub.lifecycle', 'https://example.com/hook')
            ->withSecret('test_secret_123')
            ->withFilter(['status' => 'active'])
            ->priority(10)
            ->save();

        expect($subscription)->toBeInstanceOf(Subscription::class);
        expect($subscription->secret)->toBe('test_secret_123');
        expect($subscription->active)->toBeTrue();

        // List
        $subs = EventManager::listSubscriptions('sub.lifecycle');
        expect($subs)->not->toBeEmpty();

        // Get
        $got = EventManager::getSubscription($subscription->id);
        expect($got)->not->toBeNull();
        expect($got->id)->toBe($subscription->id);

        // Unsubscribe
        $removed = EventManager::unsubscribe($subscription->id);
        expect($removed)->toBeTrue();

        expect(Subscription::find($subscription->id))->toBeNull();
    });

    it('supports event history and statistics', function (): void {
        $trigger = EventManager::on('stats.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        EventManager::fire('stats.test', ['value' => 1]);
        EventManager::fire('stats.test', ['value' => 2]);

        // History
        $history = EventManager::getEventHistory(event: 'stats.test');
        expect($history)->not->toBeEmpty();

        // Stats
        $stats = EventManager::getStats();
        expect($stats)->toHaveKey('total_logs');
        expect($stats)->toHaveKey('total_triggers');
        expect($stats['total_triggers'])->toBeGreaterThanOrEqual(1);

        // Purge (should not delete recent logs)
        EventManager::purgeLogs(
            before: now()->subYears(10),
            includePending: false,
        );
        // The log should NOT be purged
        expect(EventLog::where('event', 'stats.test')->count())->toBeGreaterThanOrEqual(2);

        EventManager::deleteTrigger($trigger->id);
    });

    it('supports register alias', function (): void {
        $trigger = EventManager::register('alias.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->name('Alias Test')
            ->save();

        expect($trigger->event)->toBe('alias.test');
        expect($trigger->name)->toBe('Alias Test');

        EventManager::deleteTrigger($trigger->id);
    });
});
