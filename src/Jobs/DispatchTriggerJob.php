<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Queued job that dispatches an event trigger and records the result.
 *
 * Created by EventManager::dispatchTrigger() for async triggers or
 * by EventsRetryCommand for manual retries. Reads queue config
 * (tries, backoff, queue name, connection) at construction time so
 * each queued job carries its own settings independent of env changes.
 *
 * The EventLog record is created inside handle() (not during construction)
 * to prevent orphaned log entries when the queue is unavailable.
 *
 * @see \ZeroBoiler\Events\EventManager::dispatchTrigger()
 */
final class DispatchTriggerJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** @var list<int> Backoff intervals in seconds between retry attempts. Populated from `events.retry.backoff` config in the constructor. */
    public readonly array $backoff;

    /** @var int Number of times the job may be attempted. Populated from `events.retry.tries` config in the constructor. */
    public readonly int $tries;

    /**
     * Create a new dispatch trigger job.
     *
     * Reads retry, backoff, queue name, and connection from config at
     * construction time so each queued job carries its own settings.
     *
     * @param  array<string, mixed>  $payload
     * @param  Container|null  $app  Container for config resolution; falls back to app() helper
     * @since 1.0.0
     */
    public function __construct(
        public readonly string $triggerId,
        public readonly string $event,
        public readonly array $payload,
        ?Container $app = null,
    ) {
        $config = $this->resolveConfig($app);

        // Retry configuration — accept int or numeric string from env()
        $triesConfig = $config->get('events.retry.tries', 3);
        $this->tries = is_int($triesConfig) && $triesConfig > 0
            ? $triesConfig
            : (is_numeric($triesConfig) && (int) $triesConfig > 0 ? (int) $triesConfig : 3);

        $backoffConfig = $config->get('events.retry.backoff', '60,300,900');
        if (is_array($backoffConfig)) {
            // Support array format directly: [60, 300, 900]
            $this->backoff = array_values(array_map(
                fn (mixed $v): int => (int) $v,
                $backoffConfig,
            ));
        } elseif (is_string($backoffConfig) && $backoffConfig !== '') {
            $parts = explode(',', $backoffConfig);
            $this->backoff = array_values(array_map(
                fn (string $v): int => (int) trim($v),
                $parts,
            ));
        } else {
            $this->backoff = [60, 300, 900];
        }

        // Queue configuration
        $queueConfig = $config->get('events.queue.queue', 'default');
        $this->queue = is_string($queueConfig) && $queueConfig !== '' ? $queueConfig : 'default';

        $connectionConfig = $config->get('events.queue.connection', null);
        $this->connection = (is_string($connectionConfig) && $connectionConfig !== '') ? $connectionConfig : null;
    }

    /**
     * Resolve the config repository from container or global helper.
     *
     * @internal Not part of the public API.
     */
    private function resolveConfig(?Container $app): ConfigRepository
    {
        if ($app !== null) {
            $config = $app->get('config');
            if ($config instanceof ConfigRepository) {
                return $config;
            }
        }

        $config = app('config');

        if ($config instanceof ConfigRepository) {
            return $config;
        }

        throw new \RuntimeException('Config repository not available.');
    }

    /**
     * The EventLog ID once created, stored on the instance so failed()
     * can reference it without a DB lookup by construction-time ID.
     * @since 1.0.0
     */
    protected ?string $eventLogId = null;

    public function handle(EventManager $eventManager): void
    {
        $trigger = Trigger::find($this->triggerId);

        if ($trigger === null || ! $trigger->enabled) {
            Log::warning('Trigger not found or disabled', [
                'trigger_id' => $this->triggerId,
            ]);

            return;
        }

        // Create the EventLog here — inside the job — so that if the job
        // never runs (queue down, Redis flushed), no orphaned log entry is
        // left behind. See bug #632.
        $log = new EventLog([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'status' => EventLog::STATUS_PENDING,
        ]);
        $log->save();

        $this->eventLogId = $log->id;

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
            if ($log instanceof EventLog) {
                $log->update([
                    'status' => EventLog::STATUS_FAILED,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
