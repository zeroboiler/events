<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Event history, statistics and log-purge operations.
 *
 * Extracted from EventManager to reduce class size and improve
 * single-responsibility. Must be used inside the EventManager class
 * which provides the `$app` container property.
 *
 * @property-read \Illuminate\Container\Container $app
 */
trait ManagesHistory
{
    use EscapesWildcardLike;

    /**
     * Get event log history with optional filtering.
     *
     * @param  string|null  $event  Filter by event name (exact or wildcard)
     * @param  string|null  $status  Filter by status (pending|dispatched|completed|failed)
     * @param  string|null  $triggerId  Filter by trigger ID
     * @param  int  $limit  Maximum number of results
     * @return Collection<int, EventLog>
     */
    public function getEventHistory(
        ?string $event = null,
        ?string $status = null,
        ?string $triggerId = null,
        int $limit = 100,
    ): Collection {
        $query = EventLog::query()->with('trigger');

        if ($event !== null && $event !== '') {
            $likePattern = $this->wildcardToLike($event);
            if ($likePattern !== null) {
                $query->where('event', 'like', $likePattern);
            } else {
                $query->where('event', $event);
            }
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($triggerId !== null && $triggerId !== '') {
            $query->where('trigger_id', $triggerId);
        }

        return $query->latest()->limit($limit)->get();
    }

    /**
     * Get aggregate statistics for events and triggers.
     *
     * Returns counts, success/failure rates, average duration, and
     * most-fired events. Useful for dashboards and monitoring.
     *
     * @param  Carbon|null  $since  Only include logs after this datetime
     * @return array{
     *     total_logs: int,
     *     total_triggers: int,
     *     active_triggers: int,
     *     completed: int,
     *     failed: int,
     *     pending: int,
     *     dispatched: int,
     *     success_rate: float|null,
     *     failure_rate: float|null,
     *     avg_duration_ms: float|null,
     *     top_events: array<int, array{event: string, count: int}>,
     *     top_failed_events: array<int, array{event: string, count: int}>
     * }
     */
    public function getStats(?Carbon $since = null): array
    {
        $logQuery = EventLog::query();

        if ($since !== null) {
            $logQuery->where('created_at', '>=', $since);
        }

        $totalLogs = (int) (clone $logQuery)->count();
        $completed = (int) (clone $logQuery)->where('status', EventLog::STATUS_COMPLETED)->count();
        $failed = (int) (clone $logQuery)->where('status', EventLog::STATUS_FAILED)->count();
        $pending = (int) (clone $logQuery)->where('status', EventLog::STATUS_PENDING)->count();
        $dispatched = (int) (clone $logQuery)->where('status', EventLog::STATUS_DISPATCHED)->count();

        $settled = $completed + $failed;
        $successRate = $settled > 0 ? round(($completed / $settled) * 100, 2) : null;
        $failureRate = $settled > 0 ? round(($failed / $settled) * 100, 2) : null;

        $avgDuration = (clone $logQuery)
            ->whereNotNull('duration_ms')
            ->avg('duration_ms');

        // avg() returns int|float|null from Eloquent
        if ($avgDuration !== null && is_numeric($avgDuration)) {
            $avgDuration = round((float) $avgDuration, 2);
        } else {
            $avgDuration = null;
        }

        // Top events by fire count
        $topEvents = (clone $logQuery)
            ->select('event', DB::raw('COUNT(*) as count'))
            ->groupBy('event')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'event' => isset($row->event) ? (string) $row->event : '',
                'count' => isset($row->count) && is_numeric($row->count) ? (int) $row->count : 0,
            ])
            ->toArray();

        // Top failed events
        $topFailedEvents = (clone $logQuery)
            ->where('status', EventLog::STATUS_FAILED)
            ->select('event', DB::raw('COUNT(*) as count'))
            ->groupBy('event')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'event' => isset($row->event) ? (string) $row->event : '',
                'count' => isset($row->count) && is_numeric($row->count) ? (int) $row->count : 0,
            ])
            ->toArray();

        $totalTriggers = (int) Trigger::count();
        $activeTriggers = (int) Trigger::enabled()->count();

        return [
            'total_logs' => $totalLogs,
            'total_triggers' => $totalTriggers,
            'active_triggers' => $activeTriggers,
            'completed' => $completed,
            'failed' => $failed,
            'pending' => $pending,
            'dispatched' => $dispatched,
            'success_rate' => $successRate,
            'failure_rate' => $failureRate,
            'avg_duration_ms' => $avgDuration,
            'top_events' => $topEvents,
            'top_failed_events' => $topFailedEvents,
        ];
    }

    /**
     * Purge old event logs.
     *
     * Deletes event logs older than the given threshold. By default,
     * only completed or failed logs are purged (not pending/dispatched
     * which may still be in progress). Use $includePending to also
     * purge stuck logs.
     *
     * @param  Carbon  $before  Delete logs created before this datetime
     * @param  bool  $includePending  Also purge pending/dispatched logs
     * @return int Number of deleted logs
     */
    public function purgeLogs(Carbon $before, bool $includePending = false): int
    {
        $query = EventLog::query()->where('created_at', '<', $before);

        if (! $includePending) {
            $query->whereIn('status', [EventLog::STATUS_COMPLETED, EventLog::STATUS_FAILED]);
        }

        return (int) $query->delete();
    }

    /**
     * Get stuck/pending event logs older than a given threshold.
     *
     * Useful for ops dashboards and manual intervention workflows
     * to identify logs stuck in pending status (e.g., queue worker crash).
     *
     * @return Collection<int, EventLog>
     */
    public function getStalePendingLogs(Carbon $before, int $limit = 100): Collection
    {
        return EventLog::query()
            ->with('trigger')
            ->stalePending($before)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Deactivate all subscriptions that have exceeded the failure threshold.
     *
     * @return int Number of deactivated subscriptions
     */
    public function deactivateExceededSubscriptions(): int
    {
        $count = 0;
        $subs = Subscription::active()->exceededFailures()->get();

        foreach ($subs as $sub) {
            $sub->update(['active' => false]);
            $count++;
        }

        return $count;
    }
}
