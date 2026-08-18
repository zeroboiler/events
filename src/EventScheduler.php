<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;

/**
 * Scheduler for automated event log maintenance tasks.
 *
 * Provides a single point of entry for registering event-related
 * scheduled tasks (log purging, subscription cleanup). Intended to be
 * called from the host application's `schedule()` method or via the
 * `EventsServiceProvider` boot process.
 *
 * Uses constructor injection for the container to avoid relying on the
 * global `app()` helper, which improves testability and PHPStan compliance.
 * Reads config through the container's `ConfigRepository` for consistency
 * with EventManager's `getConfig()` pattern.
 */
final class EventScheduler
{
    public function __construct(
        private readonly Container $app,
    ) {}

    /**
     * Get the config repository from the container with type narrowing.
     *
     * @internal Not part of the public API.
     */
    protected function getConfig(): ConfigRepository
    {
        $config = $this->app->get('config');

        if ($config instanceof ConfigRepository) {
            return $config;
        }

        throw new \RuntimeException('Config repository not available in the container.');
    }

    /**
     * Register all event-related scheduled tasks with the given scheduler.
     *
     * @param  Schedule  $schedule  The Laravel scheduler instance
     *
     * @return void
     */
    public function register(Schedule $schedule): void
    {
        $this->registerLogPurge($schedule);
        $this->registerSubscriptionCleanup($schedule);
    }

    /**
     * Resolve the EventManager from the container with type safety.
     *
     * Returns null when the binding is missing or resolved to a wrong type,
     * allowing scheduled callbacks to silently skip execution.
     *
     * @internal Not part of the public API.
     */
    protected function resolveEventManager(): ?EventManager
    {
        $resolved = $this->app->make(EventManager::class);

        return $resolved instanceof EventManager ? $resolved : null;
    }

    /**
     * Schedule automatic log retention purge.
     *
     * Purges old event logs based on the `events.retention.days` config.
     * Disabled when `retention.days` is null (set to 0 to disable).
     *
     * The schedule frequency defaults to daily at 02:00 UTC and can be
     * overridden via `events.retention.schedule_cron`.
     *
     * @internal Not part of the public API. Called automatically by register().
     */
    protected function registerLogPurge(Schedule $schedule): void
    {
        $days = $this->getConfig()->get('events.retention.days');

        // Accept int, numeric string, or float (e.g., 30.5 days)
        if ($days === null || ! is_numeric($days) || (float) $days <= 0) {
            return;
        }

        $retentionDays = (int) $days;
        $cron = $this->getConfig()->get('events.retention.schedule_cron', '0 2 * * *');
        $cronExpression = is_string($cron) && $cron !== '' ? $cron : '0 2 * * *';

        $includePending = $this->getConfig()->get('events.retention.include_pending', false);
        $scheduler = $this;

        $schedule->call(function () use ($scheduler, $retentionDays, $includePending): void {
            $eventManager = $scheduler->resolveEventManager();

            if ($eventManager === null) {
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
     *
     * @internal Not part of the public API. Called automatically by register().
     */
    protected function registerSubscriptionCleanup(Schedule $schedule): void
    {
        $cleanupCron = $this->getConfig()->get('events.subscriptions.cleanup_cron', '0 3 * * *');
        $cronExpression = is_string($cleanupCron) && $cleanupCron !== '' ? $cleanupCron : '0 3 * * *';

        $scheduler = $this;

        $schedule->call(function () use ($scheduler): void {
            $eventManager = $scheduler->resolveEventManager();

            if ($eventManager === null) {
                return;
            }

            $eventManager->deactivateExceededSubscriptions();
        })->name('zeroboiler:events:cleanup-subscriptions')
            ->cron($cronExpression)
            ->withoutOverlapping()
            ->onOneServer();
    }
}
