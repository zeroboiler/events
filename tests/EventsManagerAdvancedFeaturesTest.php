<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

// ─── EventManager::listSubscriptions ───────────────────────────────────────

describe('EventManager::listSubscriptions', function (): void {
    it('returns all subscriptions when no filters', function (): void {
        Subscription::factory()->active()->createMany(3);

        $manager = app(EventManager::class);
        $result = $manager->listSubscriptions();

        expect($result)->toHaveCount(3);
    });

    it('filters by active only', function (): void {
        Subscription::factory()->active()->createMany(3);
        Subscription::factory()->inactive()->createMany(2);

        $manager = app(EventManager::class);
        $result = $manager->listSubscriptions(null, activeOnly: true);

        expect($result)->toHaveCount(3);
    });

    it('filters by event name', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();
        Subscription::factory()->forEvent('payment.received')->create();

        $manager = app(EventManager::class);
        $result = $manager->listSubscriptions('order.placed');

        expect($result)->toHaveCount(1);
        expect($result->first()->event)->toBe('order.placed');
    });

    it('supports wildcard event filtering', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();
        Subscription::factory()->forEvent('order.shipped')->create();
        Subscription::factory()->forEvent('payment.received')->create();

        $manager = app(EventManager::class);
        $result = $manager->listSubscriptions('order.*');

        expect($result)->toHaveCount(2);
    });

    it('returns empty collection for non-matching filter', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();

        $manager = app(EventManager::class);
        $result = $manager->listSubscriptions('nonexistent.event');

        expect($result)->toHaveCount(0);
    });

    it('returns empty when event filter is empty string', function (): void {
        Subscription::factory()->create();

        $manager = app(EventManager::class);
        $result = $manager->listSubscriptions('');

        expect($result)->toHaveCount(1); // empty string should not filter
    });
});

// ─── EventManager::getStalePendingLogs ─────────────────────────────────────

describe('EventManager::getStalePendingLogs', function (): void {
    it('returns only pending logs older than threshold', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        $oldLog = EventLog::factory()->forTrigger($trigger->id)->pending()->create();
        $oldLog->created_at = Carbon::now()->subHours(3);
        $oldLog->save();

        $recentLog = EventLog::factory()->forTrigger($trigger->id)->pending()->create();

        $manager = app(EventManager::class);
        $stale = $manager->getStalePendingLogs(Carbon::now()->subHour());

        expect($stale)->toHaveCount(1);
        expect($stale->first()->id)->toBe($oldLog->id);
    });

    it('does not include completed logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        $oldCompleted = EventLog::factory()->forTrigger($trigger->id)->completed()->create();
        $oldCompleted->created_at = Carbon::now()->subHours(5);
        $oldCompleted->save();

        $manager = app(EventManager::class);
        $stale = $manager->getStalePendingLogs(Carbon::now()->subHour());

        expect($stale)->toHaveCount(0);
    });

    it('respects limit parameter', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->pending()->createMany(5)->each(function (EventLog $log): void {
            $log->created_at = Carbon::now()->subHours(2);
            $log->save();
        });

        $manager = app(EventManager::class);
        $stale = $manager->getStalePendingLogs(Carbon::now()->subHour(), limit: 2);

        expect($stale)->toHaveCount(2);
    });
});

// ─── EventManager::deactivateExceededSubscriptions ────────────────────────

describe('EventManager::deactivateExceededSubscriptions', function (): void {
    it('deactivates subscriptions exceeding failure threshold', function (): void {
        Subscription::factory()->active()->withFailureCount(15)->create();
        Subscription::factory()->active()->withFailureCount(12)->create();
        Subscription::factory()->active()->withFailureCount(3)->create();

        $manager = app(EventManager::class);
        $count = $manager->deactivateExceededSubscriptions();

        expect($count)->toBe(2);
        expect(Subscription::active()->count())->toBe(1);
    });

    it('returns zero when none exceed threshold', function (): void {
        Subscription::factory()->active()->withFailureCount(3)->create();
        Subscription::factory()->active()->withFailureCount(5)->create();

        $manager = app(EventManager::class);
        $count = $manager->deactivateExceededSubscriptions();

        expect($count)->toBe(0);
    });

    it('skips already inactive subscriptions', function (): void {
        Subscription::factory()->inactive()->withFailureCount(20)->create();

        $manager = app(EventManager::class);
        $count = $manager->deactivateExceededSubscriptions();

        expect($count)->toBe(0);
    });
});

// ─── EventManager::getStats with $since ─────────────────────────────────────

describe('EventManager::getStats with $since filter', function (): void {
    it('only counts logs after since datetime', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        $oldLog = EventLog::factory()->forTrigger($trigger->id)->completed()->create();
        $oldLog->created_at = Carbon::now()->subDays(10);
        $oldLog->save();

        EventLog::factory()->forTrigger($trigger->id)->completed()->createMany(3);
        EventLog::factory()->forTrigger($trigger->id)->failed()->create();

        $manager = app(EventManager::class);
        $stats = $manager->getStats(since: Carbon::now()->subDays(5));

        expect($stats['total_logs'])->toBe(4); // 3 completed + 1 failed
        expect($stats['completed'])->toBe(3);
        expect($stats['failed'])->toBe(1);
    });

    it('returns all-time stats when since is null', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->createMany(2);

        $manager = app(EventManager::class);
        $stats = $manager->getStats(since: null);

        expect($stats['total_logs'])->toBe(2);
        expect($stats['completed'])->toBe(2);
    });
});

// ─── EventManager::subscribeWebhook ───────────────────────────────────────

describe('EventManager::subscribeWebhook', function (): void {
    it('creates a trigger for webhook dispatch', function (): void {
        $manager = app(EventManager::class);
        $triggerId = $manager->subscribeWebhook('order.placed', 'https://example.com/hooks');

        expect($triggerId)->not->toBeEmpty();

        $trigger = Trigger::find($triggerId);
        expect($trigger)->not->toBeNull();
        expect($trigger->event)->toBe('order.placed');
        expect($trigger->enabled)->toBeTrue();
    });

    it('accepts conditions', function (): void {
        $manager = app(EventManager::class);
        $triggerId = $manager->subscribeWebhook(
            'order.placed',
            'https://example.com/hooks',
            ['status' => 'paid'],
            5,
        );

        $trigger = Trigger::find($triggerId);
        expect($trigger->conditions)->toBe(['status' => 'paid']);
        expect($trigger->priority)->toBe(5);
    });
});
