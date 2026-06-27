<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

class EventsRetryCommand extends Command
{
    protected $signature = 'zeroboiler:events:retry 
                           {--status=failed : Status to retry (failed|pending)}';

    protected $description = 'Retry failed or pending event dispatches';

    public function handle(): int
    {
        $status = $this->option('status');

        if (! in_array($status, [EventLog::STATUS_FAILED, EventLog::STATUS_PENDING], true)) {
            $this->error('Invalid status. Must be "failed" or "pending".');

            return Command::FAILURE;
        }

        $logs = EventLog::withStatus($status)->with('trigger')->get();

        if ($logs->isEmpty()) {
            $this->info("No {$status} logs found.");

            return Command::SUCCESS;
        }

        $this->info("Found {$logs->count()} {$status} log(s).");

        if (! $this->confirm('Retry them now?', true)) {
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($logs as $log) {
            /** @var Trigger|null $trigger */
            $trigger = $log->trigger;

            if (! $trigger || ! $trigger->enabled) {
                $this->warn("Skipping log {$log->id}: trigger not found or disabled");

                continue;
            }

            if ($trigger->async) {
                Queue::push(new DispatchTriggerJob($log->id));
            } else {
                try {
                    app(EventManager::class)->executeTrigger($trigger, $log);
                    $count++;
                } catch (\Throwable $e) {
                    $this->error("Failed to execute trigger {$trigger->id}: {$e->getMessage()}");
                }
            }

            if ($trigger->async) {
                $count++;
            }
        }

        $this->info("Queued/executed {$count} log(s) for retry.");

        return Command::SUCCESS;
    }
}
