<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;

class DispatchTriggerJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int|array $backoff = [60, 300, 900];

    public function __construct(
        public string $eventLogId
    ) {}

    public function handle(): void
    {
        $log = EventLog::find($this->eventLogId);

        if (! $log) {
            Log::warning('EventLog not found', ['event_log_id' => $this->eventLogId]);

            return;
        }

        $trigger = $log->trigger;

        if (! $trigger || ! $trigger->enabled) {
            Log::warning('Trigger not found or disabled', [
                'trigger_id' => $log->trigger_id,
            ]);

            return;
        }

        // Bug #407: Reset status to 'pending' before each attempt so the
        // EventLog does not stay 'dispatched' between retry attempts.
        // This makes the true state visible between job attempts.
        if ($log->status === EventLog::STATUS_DISPATCHED) {
            $log->status = EventLog::STATUS_PENDING;
            $log->save();
        }

        $eventManager = app(EventManager::class);
        $eventManager->executeTrigger($trigger, $log);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('DispatchTriggerJob failed permanently', [
            'event_log_id' => $this->eventLogId,
            'error' => $exception->getMessage(),
        ]);

        $log = EventLog::find($this->eventLogId);
        if ($log) {
            $log->status = EventLog::STATUS_FAILED;
            $log->error = $exception->getMessage();
            $log->save();
        }
    }
}
