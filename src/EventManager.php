<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

class EventManager
{
    public function __construct(
        protected ConditionEngine $conditionEngine,
        protected ActionResolver $actionResolver
    ) {}

    /**
     * Start building a new trigger.
     */
    public function on(string $event): TriggerBuilder
    {
        $builder = App::make(TriggerBuilder::class);
        $builder->on($event);

        return $builder;
    }

    /**
     * Alias for on().
     */
    public function register(string $event): TriggerBuilder
    {
        return $this->on($event);
    }

    /**
     * Fire an event and dispatch all matching triggers.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fire(string $event, array $payload = []): void
    {
        $triggers = $this->getMatchingTriggers($event);

        foreach ($triggers as $trigger) {
            if (! $this->shouldDispatch($trigger, $payload)) {
                continue;
            }

            $this->dispatchTrigger($trigger, $event, $payload);
        }
    }

    /**
     * Fire an event for a model action.
     */
    public function fireModel(string $modelClass, string $action, object $model): void
    {
        $event = $modelClass.'.'.$action;

        $this->fire($event, [
            'model' => $model,
            'model_class' => $modelClass,
            'action' => $action,
        ]);
    }

    /**
     * Enable a trigger by ID.
     */
    public function enable(string $triggerId): bool
    {
        return Trigger::where('id', $triggerId)->update(['enabled' => true]) > 0;
    }

    /**
     * Disable a trigger by ID.
     */
    public function disable(string $triggerId): bool
    {
        return Trigger::where('id', $triggerId)->update(['enabled' => false]) > 0;
    }

    /**
     * Get all triggers matching an event (including wildcards).
     *
     * @return Collection<int, Trigger>
     */
    protected function getMatchingTriggers(string $event): Collection
    {
        // Get exact matches
        $triggers = Trigger::enabled()
            ->where('event', $event)
            ->orderByPriority()
            ->get();

        // Get wildcard matches
        $allTriggers = Trigger::enabled()->get();
        foreach ($allTriggers as $trigger) {
            if (WildcardMatcher::matches($trigger->event, $event)) {
                $exists = $triggers->firstWhere('id', $trigger->id);
                if (! $exists) {
                    $triggers->push($trigger);
                }
            }
        }

        return $triggers->sortByDesc('priority')->values();
    }

    /**
     * Check if a trigger should be dispatched based on conditions.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function shouldDispatch(Trigger $trigger, array $payload): bool
    {
        if (empty($trigger->conditions)) {
            return true;
        }

        return $this->conditionEngine->matches($trigger->conditions, $payload);
    }

    /**
     * Dispatch a trigger (sync or async).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function dispatchTrigger(Trigger $trigger, string $event, array $payload): void
    {
        $log = new EventLog([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => $event,
            'payload' => $payload,
            'status' => EventLog::STATUS_PENDING,
        ]);
        $log->save();

        if ($trigger->async) {
            Queue::push(new DispatchTriggerJob($log->id));
        } else {
            $this->executeTrigger($trigger, $log);
        }
    }

    /**
     * Execute a trigger synchronously.
     */
    public function executeTrigger(Trigger $trigger, EventLog $log): void
    {
        $startTime = microtime(true);
        $log->status = EventLog::STATUS_DISPATCHED;
        $log->save();

        try {
            $actions = $this->parseActions($trigger->action);

            foreach ($actions as $actionClass) {
                /** @var Triggerable $handler */
                $handler = $this->actionResolver->resolve($actionClass);
                $handler->handle($log->payload);
            }

            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $log->markAsCompleted($duration);
        } catch (Throwable $e) {
            $log->markAsFailed($e->getMessage());
            Log::error('Event trigger failed', [
                'trigger_id' => $trigger->id,
                'event' => $log->event,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Parse action string into array of classes.
     *
     * @return array<int, string>
     */
    protected function parseActions(string $action): array
    {
        // Try to decode as JSON first
        $decoded = json_decode($action, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [$action];
    }
}
