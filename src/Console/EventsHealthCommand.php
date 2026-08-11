<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Diagnostic health check command for the events system.
 *
 * Useful in production for monitoring dashboards, pre-deploy checks,
 * and ops runbooks. Reports on database connectivity, trigger counts,
 * subscription health, queue configuration, and cache status.
 */
final class EventsHealthCommand extends Command
{
    protected string $signature = 'zeroboiler:events:health
                           {--check-cache : Verify wildcard trigger cache is reachable}
                           {--json : Output results as JSON}';

    protected string $description = 'Check event system health and configuration';

    /**
     * Execute the health check command.
     *
     * @return int Command exit code (SUCCESS if all checks pass, FAILURE if any critical check fails)
     */
    #[\Override]
    public function handle(): int
    {
        $results = [];
        $hasCritical = false;

        // 1. Global disable check
        $disabled = Config::get('events.disabled', false);
        $results['global_disabled'] = [
            'status' => $disabled === true ? 'WARNING' : 'OK',
            'message' => $disabled === true
                ? 'Event system is globally disabled (EVENTS_DISABLED=true or setEnabled(false)).'
                : 'Event system is enabled.',
        ];

        // 2. Database connectivity — try to query triggers table
        $totalTriggers = 0;
        try {
            $totalTriggers = Trigger::count();
            $results['database'] = [
                'status' => 'OK',
                'message' => "Database connection healthy. {$totalTriggers} trigger(s) in table.",
                'trigger_count' => $totalTriggers,
            ];
        } catch (\Throwable $e) {
            $hasCritical = true;
            $results['database'] = [
                'status' => 'CRITICAL',
                'message' => 'Database connection failed: '.$e->getMessage(),
            ];
        }

        // 3. Active triggers count (reuse DB query result from step 2)
        $activeTriggers = $totalTriggers > 0 ? Trigger::enabled()->count() : 0;
        $results['active_triggers'] = [
            'status' => $totalTriggers > 0 && $activeTriggers === 0 ? 'WARNING' : 'OK',
            'message' => "{$activeTriggers} active / {$totalTriggers} total trigger(s).",
            'active' => $activeTriggers,
            'total' => $totalTriggers,
        ];

        // 4. Subscription health
        $totalSubs = Subscription::count();
        $activeSubs = Subscription::active()->count();
        $failedSubs = Subscription::where('active', false)->count();
        $results['subscriptions'] = [
            'status' => $failedSubs > 0 ? 'WARNING' : 'OK',
            'message' => "{$activeSubs} active / {$totalSubs} total subscription(s). {$failedSubs} inactive.",
            'active' => $activeSubs,
            'total' => $totalSubs,
            'inactive' => $failedSubs,
        ];

        // 5. Recent event log status
        try {
            $recentFailed = EventLog::failed()->where('created_at', '>=', now()->subHours(24))->count();
            $recentCompleted = EventLog::completed()->where('created_at', '>=', now()->subHours(24))->count();
            $results['recent_events_24h'] = [
                'status' => $recentFailed > 10 ? 'WARNING' : 'OK',
                'message' => "Last 24h: {$recentCompleted} completed, {$recentFailed} failed.",
                'completed' => $recentCompleted,
                'failed' => $recentFailed,
            ];
        } catch (\Throwable $e) {
            $results['recent_events_24h'] = [
                'status' => 'WARNING',
                'message' => 'Could not query event logs: '.$e->getMessage(),
            ];
        }

        // 6. Queue configuration
        $queueConnection = Config::get('events.queue.connection', config('queue.default', 'default'));
        $queueName = Config::get('events.queue.queue', 'default');
        $retryTries = Config::get('events.retry.tries', 3);
        $results['queue_config'] = [
            'status' => 'OK',
            'message' => "Connection: {$queueConnection}, Queue: {$queueName}, Retries: {$retryTries}.",
            'connection' => is_string($queueConnection) ? $queueConnection : 'default',
            'queue' => is_string($queueName) ? $queueName : 'default',
            'retries' => is_int($retryTries) ? $retryTries : 3,
        ];

        // 7. Cache check (optional)
        if ($this->option('check-cache')) {
            $cacheKey = 'zeroboiler:events:health_check';
            try {
                Cache::put($cacheKey, 'ok', 10);
                $retrieved = Cache::get($cacheKey);
                Cache::forget($cacheKey);
                $results['cache'] = [
                    'status' => $retrieved === 'ok' ? 'OK' : 'CRITICAL',
                    'message' => $retrieved === 'ok'
                        ? 'Cache driver is reachable and functional.'
                        : 'Cache put/get roundtrip failed.',
                ];
            } catch (\Throwable $e) {
                $hasCritical = true;
                $results['cache'] = [
                    'status' => 'CRITICAL',
                    'message' => 'Cache driver error: '.$e->getMessage(),
                ];
            }
        }

        // Output
        if ($this->option('json')) {
            $this->line(json_encode($results, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            return $hasCritical ? Command::FAILURE : Command::SUCCESS;
        }

        $this->info('ZeroBoiler Events Health Check');
        $this->line('============================');

        foreach ($results as $check => $data) {
            $status = $data['status'];
            $message = $data['message'];
            $icon = match ($status) {
                'OK' => '<fg=green>✔</>',
                'WARNING' => '<fg=yellow>⚠</>',
                'CRITICAL' => '<fg=red>✘</>',
                default => '?',
            };
            $label = str_replace('_', ' ', ucfirst($check));
            $this->line("  {$icon} <bold>{$label}:</bold> {$message}");
        }

        $this->line('');
        if ($hasCritical) {
            $this->error('One or more critical issues detected.');

            return Command::FAILURE;
        }

        $this->info('All checks passed.');

        return Command::SUCCESS;
    }
}
