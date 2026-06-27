<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\Models\Trigger;

class EventsListCommand extends Command
{
    protected $signature = 'zeroboiler:events:list';

    protected $description = 'List all event triggers';

    public function handle(): int
    {
        $triggers = Trigger::orderByPriority()->get();

        if ($triggers->isEmpty()) {
            $this->info('No triggers found.');

            return Command::SUCCESS;
        }

        $headers = ['ID', 'Name', 'Event', 'Action', 'Async', 'Priority', 'Enabled', 'Created At'];
        $rows = $triggers->map(fn (Trigger $t) => [
            $t->id,
            $t->name,
            $t->event,
            $t->action,
            $t->async ? 'Yes' : 'No',
            $t->priority,
            $t->enabled ? 'Yes' : 'No',
            $t->created_at->format('Y-m-d H:i'),
        ])->toArray();

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }
}
