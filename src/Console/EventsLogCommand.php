<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Models\EventLog;

/**
 * View event logs with optional filtering.
 *
 * Supports filtering by event name (with wildcards), trigger ID, status,
 * and result limiting.
 */
final class EventsLogCommand extends Command
{
    use EscapesWildcardLike;

    protected string $signature = 'zeroboiler:events:log
                           {--event= : Filter by event name (supports wildcards)}
                           {--trigger= : Filter by trigger ID}
                           {--status= : Filter by status (pending|dispatched|completed|failed)}
                           {--limit=50 : Number of logs to show}';

    protected string $description = 'View event logs';

    #[\Override]
    public function handle(): int
    {
        $query = EventLog::query();

        $eventFilter = $this->option('event');
        if ($eventFilter !== null && $eventFilter !== '') {
            $eventString = is_string($eventFilter) ? $eventFilter : '';
            $likePattern = $this->wildcardToLike($eventString);
            if ($likePattern !== null) {
                $query->where('event', 'like', $likePattern);
            } else {
                $query->where('event', $eventString);
            }
        }

        $triggerId = $this->option('trigger');
        if (is_string($triggerId) && $triggerId !== '') {
            $query->where('trigger_id', $triggerId);
        }

        $status = $this->option('status');
        if (is_string($status) && $status !== '') {
            if (! in_array($status, EventLog::$statuses, true)) {
                $this->error('Invalid status. Must be one of: '.implode(', ', EventLog::$statuses));

                return Command::FAILURE;
            }

            $query->where('status', $status);
        }

        $limit = (int) $this->option('limit');
        $logs = $query->with('trigger')->latest()->limit($limit)->get();

        if ($logs->isEmpty()) {
            $this->info('No event logs found.');

            return Command::SUCCESS;
        }

        $headers = ['ID', 'Event', 'Trigger', 'Status', 'Duration', 'Created At'];
        $rows = $logs->map(function (EventLog $log): array {
            $triggerName = $log->trigger !== null ? $log->trigger->name : 'N/A';

            return [
                $log->id,
                $log->event,
                $triggerName,
                $this->formatStatus($log->status),
                $log->duration_ms !== null ? "{$log->duration_ms}ms" : 'N/A',
                $log->created_at?->format('Y-m-d H:i:s') ?? '—',
            ];
        })->toArray();

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }

    protected function formatStatus(string $status): string
    {
        return match ($status) {
            EventLog::STATUS_COMPLETED => '<fg=green>completed</>',
            EventLog::STATUS_FAILED => '<fg=red>failed</>',
            EventLog::STATUS_PENDING => '<fg=yellow>pending</>',
            EventLog::STATUS_DISPATCHED => '<fg=blue>dispatched</>',
            default => $status,
        };
    }
}
