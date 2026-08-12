<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * Scheduler for automated event log maintenance tasks.
 *
 * Provides a single point of entry for registering event-related
 * scheduled tasks (log purging, subscription cleanup). Intended to be
 * called from the host application's `schedule()` method or via the
 * `EventsServiceProvider` boot process.
 */
final class EventScheduler
{
    /**
     * Register all event-related scheduled tasks with the given scheduler.
     *
     * @param  Schedule  $schedule  The Laravel scheduler instance
     */
    public function register(Schedule $schedule): void
    {
        $this->registerLogPurge($schedule);
        $this->registerSubscriptionCleanup($schedule);
    }

    /**
     * Schedule automatic log retention purge.
     *
     * Purges old event logs based on the `events.retention.days` config.
     * Disabled when `retention.days` is null (set to 0 to disable).
     *
     * The schedule frequency defaults to daily at 02:00 UTC and can be
     * overridden via `events.retention.schedule_cron`.
     */
    protected function registerLogPurge(Schedule $schedule): void
    {
        $days = Config::get('events.retention.days');

        if ($days === null || ! is_numeric($days) || (int) $days <= 0) {
            return;
        }

        $retentionDays = (int) $days;
        $cron = Config::get('events.retention.schedule_cron', '0 2 * * *');
        $cronExpression = is_string($cron) && $cron !== '' ? $cron : '0 2 * * *';

        $includePending = Config::get('events.retention.include_pending', false);

        $schedule->call(function () use ($retentionDays, $includePending): void {
            $eventManager = app(EventManager::class);

            if (! $eventManager instanceof EventManager) {
                return;
            }

            $eventManager->purgeLogs(
                before: Carbon::now()->subDays($retentionDays),
                includePending: (bool) $includePending,
            );
        })->name('zeroboiler:events:purge-logs')
            ->cron($cronExpression)
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Schedule automatic deactivation of subscriptions that have exceeded
     * the failure threshold.
     *
     * Runs daily at 03:00 UTC by default. Override via
     * `events.subscriptions.cleanup_cron`.
     */
    protected function registerSubscriptionCleanup(Schedule $schedule): void
    {
        $cleanupCron = Config::get('events.subscriptions.cleanup_cron', '0 3 * * *');
        $cronExpression = is_string($cleanupCron) && $cleanupCron !== '' ? $cleanupCron : '0 3 * * *';

        $schedule->call(function (): void {
            $eventManager = app(EventManager::class);

            if (! $eventManager instanceof EventManager) {
                return;
            }

            $eventManager->deactivateExceededSubscriptions();
        })->name('zeroboiler:events:cleanup-subscriptions')
            ->cron($cronExpression)
            ->withoutOverlapping()
            ->onOneServer();
    }
}
