<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Models\EventLog;

class EventsCleanupCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:cleanup
                           {--days= : Days to retain logs (overrides config)}
                           {--status= : Filter by status (pending|dispatched|completed|failed)}
                           {--force : Skip confirmation prompt}';

    /** @var string */
    protected $description = 'Clean up old event logs based on retention policy';

    public function handle(): int
    {
        $days = $this->resolveDays();
        $status = $this->resolveStatus();

        if ($days <= 0) {
            $this->info('Retention days is 0 or less — skipping cleanup.');

            return Command::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);

        $query = EventLog::where('created_at', '<', $cutoff);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No event logs to clean up.');

            return Command::SUCCESS;
        }

        $statusLabel = $status !== null ? " with status '{$status}'" : '';
        $this->info("Found {$total} log(s) older than {$days} day(s){$statusLabel}.");

        if (! $this->shouldProceed()) {
            $this->info('Cleanup cancelled.');

            return Command::SUCCESS;
        }

        // Collect IDs first, then delete in batches to avoid
        // offset issues when records disappear mid-iteration.
        $ids = $query->pluck('id')->all();
        $deleted = 0;
        foreach (array_chunk($ids, 200) as $batch) {
            /** @var array<int, string> $batch */
            $count = EventLog::whereIn('id', $batch)->delete();
            $deleted += (int) $count;
        }

        $this->info("Soft-deleted {$deleted} log(s).");

        return Command::SUCCESS;
    }

    private function resolveDays(): int
    {
        $daysOption = $this->option('days');

        if (is_string($daysOption) && $daysOption !== '') {
            return (int) $daysOption;
        }

        /** @var int $configDays */
        $configDays = Config::get('events.retention_days', 30);

        return $configDays;
    }

    private function resolveStatus(): ?string
    {
        $statusOption = $this->option('status');

        if (! is_string($statusOption) || $statusOption === '') {
            return null;
        }

        if (! in_array($statusOption, EventLog::$statuses, true)) {
            $this->warn("Unknown status '{$statusOption}'. Valid statuses: ".implode(', ', EventLog::$statuses).'.');

            return null;
        }

        return $statusOption;
    }

    private function shouldProceed(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        return $this->confirm('Proceed with cleanup?', true);
    }
}
