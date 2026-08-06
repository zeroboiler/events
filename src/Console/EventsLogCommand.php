<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\Models\EventLog;

class EventsLogCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:log
                           {--trigger= : Filter by trigger ID}
                           {--status= : Filter by status (pending|dispatched|completed|failed)}
                           {--limit=50 : Number of logs to show}';

    /** @var string */
    protected $description = 'View event logs';

    public function handle(): int
    {
        $query = EventLog::query();

        $triggerId = $this->option('trigger');
        if ($triggerId !== null && $triggerId !== '') {
            $query->where('trigger_id', $triggerId);
        }

        $status = $this->option('status');
        if ($status !== null && $status !== '') {
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
        $rows = $logs->map(fn (EventLog $log): array => [
            $log->id,
            $log->event,
            $log->trigger->name ?? 'N/A',
            $this->formatStatus($log->status),
            $log->duration_ms ? "{$log->duration_ms}ms" : 'N/A',
            $log->created_at->format('Y-m-d H:i:s'),
        ])->toArray();

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
