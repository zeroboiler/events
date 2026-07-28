<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Observability\Trace;

class EventManager
{
    /**
     * Cache key for all enabled triggers collection.
     */
    protected const ENABLED_TRIGGERS_CACHE_KEY = 'zeroboiler:events:enabled_triggers';

    /**
     * Cache TTL in seconds (5 minutes).
     */
    protected const TRIGGER_CACHE_TTL = 300;

    public function __construct(
        protected ConditionEngine $conditionEngine,
        protected ActionResolver $actionResolver
    ) {}

    /**
     * Start building a new trigger.
     */
    public function on(string $event): TriggerBuilder
    {
        /** @var TriggerBuilder $builder */
        $builder = App::make(TriggerBuilder::class);
        $builder->on($event);

        return $builder;
    }

    /**
     * Start building a webhook subscription for an external system.
     *
     * Creates a SubscriptionBuilder that registers a webhook trigger
     * when saved. Includes HMAC signing, condition filtering, and
     * delivery tracking.
     *
     * @param  string  $event  Event name (supports wildcards)
     * @param  string  $url  Webhook endpoint URL
     */
    public function subscribe(string $event, string $url): SubscriptionBuilder
    {
        /** @var SubscriptionBuilder $builder */
        $builder = App::make(SubscriptionBuilder::class);
        $builder->on($event)->to($url);

        return $builder;
    }

    /**
     * Remove a webhook subscription by its ID.
     *
     * Deletes the subscription record. Does not delete the associated
     * trigger (use disable() for that if needed).
     */
    public function unsubscribe(string $subscriptionId): bool
    {
        $subscription = Subscription::find($subscriptionId);

        if ($subscription === null) {
            return false;
        }

        $subscription->delete();

        return true;
    }

    /**
     * List webhook subscriptions with optional filtering.
     *
     * @param  string|null  $event  Filter by event name (supports wildcards)
     * @param  bool  $activeOnly  Show only active subscriptions
     * @return Collection<int, Subscription>
     */
    public function listSubscriptions(?string $event = null, bool $activeOnly = false): Collection
    {
        $query = Subscription::query();

        if ($event !== null && $event !== '') {
            if (str_contains($event, '*')) {
                // Escape LIKE special characters before substituting wildcard
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $event);
                $likePattern = str_replace('*', '%', $escaped);
                $query->where('event', 'like', $likePattern);
            } else {
                $query->where('event', $event);
            }
        }

        if ($activeOnly) {
            $query->active();
        }

        /** @var Collection<int, Subscription> $result */
        $result = $query->orderByPriority()->get();

        return $result;
    }

    /**
     * Get a subscription by ID.
     */
    public function getSubscription(string $subscriptionId): ?Subscription
    {
        return Subscription::find($subscriptionId);
    }

    /**
     * Invalidate the trigger cache.
     *
     * Call after register / unregister / enable / disable.
     */
    public function invalidateTriggerCache(): void
    {
        Cache::forget(self::ENABLED_TRIGGERS_CACHE_KEY);
    }

    /**
     * Alias for on().
     */
    public function register(string $event): TriggerBuilder
    {
        return $this->on($event);
    }

    /**
     * Subscribe an external webhook URL to an event.
     *
     * Registers a trigger that dispatches an HTTP POST to the given
     * URL whenever the event fires. Optional conditions can be provided
     * to filter when the webhook is actually called.
     *
     * @param  string  $event  Event name (supports wildcards)
     * @param  string  $url  Webhook endpoint URL
     * @param  array<string, mixed>  $conditions  Optional condition filters
     * @param  int  $priority  Trigger priority (higher = first)
     * @return string The created trigger ID
     */
    public function subscribeWebhook(
        string $event,
        string $url,
        array $conditions = [],
        int $priority = 0,
    ): string {
        $trigger = $this->register($event)
            ->action(WebhookAction::class)
            ->actionParams(['url' => $url])
            ->when($conditions)
            ->priority($priority)
            ->name("Webhook: {$event} → {$url}")
            ->save();

        return $trigger->id;
    }

    /**
     * Fire an event and dispatch all matching triggers.
     *
     * If a trigger throws, the exception is logged and the loop continues
     * so that one failing trigger does not prevent later triggers from
     * firing. The first exception (if any) is re-thrown after all triggers
     * have been attempted, preserving the original error for the caller
     * while avoiding partial dispatch.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $type  Event type filter: 'domain', 'integration', or 'system'. Default 'integration'.
     */
    #[Trace(operation: 'events.fire')]
    public function fire(string $event, array $payload = [], string $type = 'integration'): void
    {
        $triggers = $this->getMatchingTriggers($event, $type);

        $firstException = null;

        foreach ($triggers as $trigger) {
            if (! $this->shouldDispatch($trigger, $payload)) {
                continue;
            }

            try {
                $this->dispatchTrigger($trigger, $event, $payload);
            } catch (Throwable $e) {
                // Log the failure and continue so other triggers still fire.
                if ($firstException === null) {
                    $firstException = $e;
                }

                Log::warning('EventManager: trigger dispatch failed, continuing with remaining triggers', [
                    'event' => $event,
                    'trigger_id' => $trigger->id,
                    'trigger_name' => $trigger->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Re-throw the first exception after all triggers have been attempted
        // so the caller knows something went wrong.
        if ($firstException !== null) {
            throw $firstException;
        }
    }

    /**
     * Fire an event for a model action.
     *
     * @param  string  $type  Event type filter. Default 'integration'.
     *
     * The model is serialised to an array so the payload stays safe for
     * async/queue dispatch (#9). The raw model object is never placed
     * into the payload — only its attributes and class name.
     */
    #[Trace(operation: 'events.fire_model')]
    public function fireModel(string $modelClass, string $action, object $model, string $type = 'integration'): void
    {
        $event = $modelClass.'.'.$action;

        // Store only model's class and primary key to avoid JSON serialization issues
        // Model can be re-fetched from DB when the action executes
        $payload = [
            'model_class' => $modelClass,
            'model_id' => method_exists($model, 'getKey') ? $model->getKey() : null,
            'action' => $action,
        ];

        // Flatten model attributes into the payload root so the condition
        // engine can access them directly (e.g. condition "status == 'active'"
        // instead of "model.status == 'active'").
        /** @var array<string, mixed> $modelData */
        $modelData = [];
        if (method_exists($model, 'attributesToArray')) {
            /** @var array<string, mixed> $data */
            $data = $model->attributesToArray();
            $modelData = $data;
        } elseif (method_exists($model, 'toArray')) {
            /** @var array<string, mixed> $data */
            $data = $model->toArray();
            $modelData = $data;
        }

        $this->fire($event, [...$modelData, ...$payload], $type);
    }

    /**
     * Enable a trigger by ID.
     */
    public function enable(string $triggerId): bool
    {
        $result = Trigger::where('id', $triggerId)->update(['enabled' => true]) > 0;

        if ($result) {
            $this->invalidateTriggerCache();
        }

        return $result;
    }

    /**
     * Disable a trigger by ID.
     */
    public function disable(string $triggerId): bool
    {
        $result = Trigger::where('id', $triggerId)->update(['enabled' => false]) > 0;

        if ($result) {
            $this->invalidateTriggerCache();
        }

        return $result;
    }

    /**
     * Get all triggers matching an event (exact + wildcard).
     *
     * Loads all enabled triggers in a single cached query, then partitions
     * into exact and wildcard matches in PHP. This avoids per-fire() DB
     * queries for exact matches and eliminates the N+1 problem where every
     * enabled trigger was loaded on each dispatch.
     *
     * @param  string  $type  Filter by trigger type ('domain', 'integration', 'system', or null for all).
     * @return Collection<int, Trigger>
     */
    protected function getMatchingTriggers(string $event, ?string $type = null): Collection
    {
        $allTriggers = $this->getEnabledTriggers();

        $matched = $allTriggers->filter(function (Trigger $trigger) use ($event, $type): bool {
            // Filter by type if specified
            if ($type !== null && $trigger->type !== $type) {
                return false;
            }
            // Exact match — no wildcard processing needed
            if ($trigger->event === $event) {
                return true;
            }

            // Wildcard match — only run matcher for patterns containing '*'
            if (str_contains($trigger->event, '*')) {
                return WildcardMatcher::matches($trigger->event, $event);
            }

            return false;
        });

        // Sort by priority DESC, then by created_at ASC as a tiebreaker for equal priorities.
        // Add trigger id as final tiebreaker for fully deterministic ordering.
        return $matched->sortBy(callback: fn (Trigger $t): array => [-$t->priority, $t->created_at?->getTimestamp() ?? 0, (string) $t->id], options: SORT_REGULAR)->values();
    }

    /**
     * Get all enabled triggers (cached).
     *
     * A single DB query populates the cache; subsequent fire() calls
     * within the TTL serve from cache — zero DB queries.
     * Cache is invalidated on register / enable / disable.
     *
     * @return Collection<int, Trigger>
     */
    protected function getEnabledTriggers(): Collection
    {
        /** @var Collection<int, Trigger> $result */
        $result = Cache::remember(self::ENABLED_TRIGGERS_CACHE_KEY, self::TRIGGER_CACHE_TTL, function (): Collection {
            /** @var Collection<int, Trigger> $triggers */
            $triggers = Trigger::enabled()
                ->orderByPriority()
                ->get();

            return $triggers;
        });

        return $result;
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
        if ($trigger->async) {
            // Create the EventLog inside the job so that if the job never
            // runs (queue down, Redis flushed, etc.) no orphaned log entry
            // is left behind in the database. See bug #632.
            Queue::push(new DispatchTriggerJob(
                $trigger->id,
                $event,
                $payload,
            ));
        } else {
            $log = new EventLog([
                'trigger_id' => $trigger->id,
                'event' => $event,
                'payload' => $payload,
                'status' => EventLog::STATUS_PENDING,
            ]);
            $log->save();

            $this->executeTrigger($trigger, $log);
        }
    }

    /**
     * Execute a trigger synchronously.
     *
     * Uses atomic status transition (pending → dispatched) to prevent
     * concurrent execution by retry workers. If the EventLog is no longer
     * pending, execution is skipped.
     */
    #[Trace(operation: 'events.execute_trigger')]
    public function executeTrigger(Trigger $trigger, EventLog $log): void
    {
        // Atomically transition from PENDING → DISPATCHED to prevent
        // concurrent execution by retry workers (issue #7).
        $updated = EventLog::where('id', $log->id)
            ->where('status', EventLog::STATUS_PENDING)
            ->update([
                'status' => EventLog::STATUS_DISPATCHED,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            // Another worker already picked up this log — skip execution.
            Log::info('EventLog already dispatched by another worker, skipping', [
                'event_log_id' => $log->id,
                'trigger_id' => $trigger->id,
            ]);

            return;
        }

        // Refresh the model to get the latest state
        $log->refresh();

        $startTime = microtime(true);

        try {
            $actions = $this->parseActions($trigger->action);

            foreach ($actions as $entry) {
                // parseActions returns a normalised array where each entry
                // is either a class name string or an array with 'class'
                // and optional 'params'.
                if (is_array($entry)) {
                    $actionClass = $entry['class'] ?? '';
                    $actionParams = $entry['params'] ?? [];
                } else {
                    $actionClass = (string) $entry;
                    $actionParams = [];
                }

                $handler = $this->actionResolver->resolve($actionClass);

                // Merge trigger-level params (e.g. webhook URL) into the
                // event payload so the action has everything it needs.
                $payload = $log->payload;
                if (! empty($actionParams)) {
                    $payload = array_merge($actionParams, $payload);
                }

                $handler->handle($payload);
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
     * Parse action string into normalised array of action entries.
     *
     * Each entry is either:
     * - A class name string (simple format):  ["App\\Actions\\Foo"]
     * - An array with 'class' and 'params':  [['class' => '...', 'params' => [...]]]
     *
     * Supports:
     * - Single class name string:  "App\\Actions\\Foo"
     * - JSON array of class names:  ["App\\Actions\\Foo", "App\\Actions\\Bar"]
     * - JSON object with class + params:  {"class": "...", "params": {...}}
     * - JSON array of objects:  [{"class": "...", "params": {...}}, ...]
     *
     * @return array<int, mixed>
     */
    protected function parseActions(string $action): array
    {
        // Try to decode as JSON first
        $decoded = json_decode($action, true);

        if (is_array($decoded)) {
            // Associative array → single action with class + params
            if (array_is_list($decoded)) {
                // List of entries — normalise each one
                return array_map(
                    fn (mixed $entry): mixed => is_array($entry) ? $entry : (string) $entry,
                    $decoded,
                );
            }

            // Associative array → single action with class + params
            return [$decoded];
        }

        return [$action];
    }
}
