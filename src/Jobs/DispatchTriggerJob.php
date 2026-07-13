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
use ZeroBoiler\Events\Models\Trigger;

class DispatchTriggerJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $triggerId,
        public string $event,
        public array $payload,
    ) {}

    /**
     * The EventLog ID once created, stored on the instance so failed()
     * can reference it without a DB lookup by construction-time ID.
     */
    protected ?string $eventLogId = null;

    public function handle(): void
    {
        $trigger = Trigger::find($this->triggerId);

        if (! $trigger || ! $trigger->enabled) {
            Log::warning('Trigger not found or disabled', [
                'trigger_id' => $this->triggerId,
            ]);

            return;
        }

        // Create the EventLog here — inside the job — so that if the job
        // never runs (queue down, Redis flushed), no orphaned log entry is
        // left behind. See bug #632.
        $log = new EventLog([
            'trigger_id' => $trigger->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'status' => EventLog::STATUS_PENDING,
        ]);
        $log->save();

        $this->eventLogId = $log->id;

        $eventManager = app(EventManager::class);
        $eventManager->executeTrigger($trigger, $log);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('DispatchTriggerJob failed permanently', [
            'trigger_id' => $this->triggerId,
            'event' => $this->event,
            'event_log_id' => $this->eventLogId,
            'error' => $exception->getMessage(),
        ]);

        // If the EventLog was created before the failure, mark it as failed.
        // If the job failed before creating the log (e.g. DB down), there is
        // nothing to update — and no orphaned entry left behind.
        if ($this->eventLogId !== null) {
            $log = EventLog::find($this->eventLogId);
            if ($log) {
                $log->status = EventLog::STATUS_FAILED;
                $log->error = $exception->getMessage();
                $log->save();
            }
        }
    }
}
