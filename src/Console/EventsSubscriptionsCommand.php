<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Models\Subscription;
/**
 * List external webhook subscriptions with optional filtering.
 *
 * Supports filtering by event name (with wildcards), active/inactive
 * status, and pagination via --per-page and --page options.
 */
final class EventsSubscriptionsCommand extends Command
{
    use EscapesWildcardLike;

    protected string $signature = 'zeroboiler:events:subscriptions
                           {--event= : Filter by event name (supports wildcards)}
                           {--active : Show only active subscriptions}
                           {--inactive : Show only inactive subscriptions}
                           {--per-page=20 : Number of results per page}
                           {--page=1 : Page number}';

    protected string $description = 'List external webhook subscriptions';

    #[\Override]
    public function handle(): int
    {
        $query = Subscription::query();

        $eventFilter = $this->option('event');
        if (is_string($eventFilter) && $eventFilter !== '') {
            $likePattern = $this->wildcardToLike($eventFilter);
            if ($likePattern !== null) {
                $query->where('event', 'like', $likePattern);
            } else {
                $query->where('event', $eventFilter);
            }
        }

        if ($this->option('active')) {
            $query->where('active', true);
        } elseif ($this->option('inactive')) {
            $query->where('active', false);
        }

        $perPage = max(1, (int) $this->option('per-page'));
        $page = max(1, (int) $this->option('page'));

        $total = $query->count();

        if ($total === 0) {
            $this->info('No subscriptions found.');

            return Command::SUCCESS;
        }

        $subscriptions = $query
            ->orderByDesc('priority')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $headers = ['ID', 'Event', 'URL', 'Priority', 'Active', 'Failures', 'Last Fired', 'Created'];
        $rows = $subscriptions->map(function (Subscription $s): array {
            $lastFired = $s->last_fired_at !== null ? $s->last_fired_at->format('Y-m-d H:i') : '—';

            return [
                $s->id,
                $s->event,
                $s->url,
                $s->priority,
                $s->active ? 'Yes' : 'No',
                $s->failure_count,
                $lastFired,
                $s->created_at?->format('Y-m-d H:i') ?? '—',
            ];
        })->toArray();

        $this->table($headers, $rows);

        $totalPages = (int) ceil($total / $perPage);
        $this->info("Page {$page} of {$totalPages} ({$total} subscription(s), showing ".$subscriptions->count().')');

        return Command::SUCCESS;
    }
}
