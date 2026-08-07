<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Models\Trigger;

final class EventsListCommand extends Command
{
    use EscapesWildcardLike;

    protected string $signature = 'zeroboiler:events:list
                           {--event= : Filter by event name (supports wildcards)}
                           {--enabled : Show only enabled triggers}
                           {--disabled : Show only disabled triggers}
                           {--per-page=20 : Number of results per page}
                           {--page=1 : Page number}';

    protected string $description = 'List event triggers with optional filtering';

    #[\Override]
    public function handle(): int
    {
        $query = Trigger::query();

        // Filter by event name (supports wildcards via LIKE)
        $eventFilter = $this->option('event');
        if ($eventFilter !== null && $eventFilter !== '') {
            $likePattern = $this->wildcardToLike((string) $eventFilter);
            if ($likePattern !== null) {
                $query->where('event', 'like', $likePattern);
            } else {
                $query->where('event', (string) $eventFilter);
            }
        }

        // Filter by enabled/disabled
        if ($this->option('enabled')) {
            $query->where('enabled', true);
        } elseif ($this->option('disabled')) {
            $query->where('enabled', false);
        }

        $perPage = max(1, (int) $this->option('per-page'));
        $page = max(1, (int) $this->option('page'));

        $total = $query->count();

        if ($total === 0) {
            $this->info('No triggers found.');

            return Command::SUCCESS;
        }

        $triggers = $query
            ->orderByDesc('priority')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $headers = ['ID', 'Name', 'Event', 'Action', 'Async', 'Priority', 'Enabled', 'Created At'];
        $rows = $triggers->map(fn (Trigger $t): array => [
            $t->id,
            $t->name,
            $t->event,
            $t->action,
            $t->async ? 'Yes' : 'No',
            $t->priority,
            $t->enabled ? 'Yes' : 'No',
            $t->created_at?->format('Y-m-d H:i') ?? '—',
        ])->toArray();

        $this->table($headers, $rows);

        $totalPages = (int) ceil($total / $perPage);
        $this->info("Page {$page} of {$totalPages} ({$total} trigger(s) total, showing ".$triggers->count().')');

        return Command::SUCCESS;
    }
}
