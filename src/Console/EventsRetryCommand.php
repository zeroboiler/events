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

/**
 * Retry failed or pending event dispatches.
 *
 * Re-queues async triggers or re-executes sync triggers for logs
 * matching the specified status filter.
 */
final class EventsRetryCommand extends Command
{
    protected string $signature = 'zeroboiler:events:retry
                           {--status=failed : Status to retry (failed|pending)}';

    protected string $description = 'Retry failed or pending event dispatches';

    #[\Override]
    public function handle(EventManager $eventManager): int
    {
        $statusOption = $this->option('status');
        $status = is_string($statusOption) ? $statusOption : '';

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

            if ($trigger === null || ! $trigger->enabled) {
                $this->warn("Skipping log {$log->id}: trigger not found or disabled");

                continue;
            }

            if ($trigger->async) {
                // Re-dispatch with the new job signature — a fresh EventLog
                // will be created inside the job. The old log remains as a
                // historical record of the previous attempt.
                $payload = is_array($log->payload) ? $log->payload : [];

                Queue::push(new DispatchTriggerJob(
                    $trigger->id,
                    $log->event,
                    $payload,
                ));
            } else {
                try {
                    $eventManager->executeTrigger($trigger, $log);
                } catch (\Throwable $e) {
                    $this->error("Failed to execute trigger {$trigger->id}: {$e->getMessage()}");

                    continue;
                }
            }

            $count++;
        }

        $this->info("Queued/executed {$count} log(s) for retry.");

        return Command::SUCCESS;
    }
}
