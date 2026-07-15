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
use Illuminate\Support\Str;
use Throwable;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

class EventManager
{
    /**
     * Cache key for the enabled wildcard triggers collection.
     */
    protected const WILDCARD_TRIGGER_CACHE_KEY = 'zeroboiler:events:enabled_wildcard_triggers';

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

        return $query->orderByPriority()->get();
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
        Cache::forget(self::WILDCARD_TRIGGER_CACHE_KEY);
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

        // Flatten model attributes into the payload root so the condition
        // engine can access them directly (e.g. condition "status == 'active'"
        // instead of "model.status == 'active'").
        $modelData = [];
        if (method_exists($model, 'attributesToArray')) {
            $modelData = $model->attributesToArray();
        } elseif (method_exists($model, 'toArray')) {
            $modelData = $model->toArray();
        }

        $this->fire($event, [
            ...$modelData,
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
     * Get all triggers matching an event (including wildcards).
     *
     * Uses a cached collection of enabled triggers with wildcards to avoid
     * loading all triggers on every fire() call. Exact (non-wildcard) matches
     * are queried directly from the DB for freshness.
     *
     * @return Collection<int, Trigger>
     */
    protected function getMatchingTriggers(string $event): Collection
    {
        // Exact matches — always queried directly (cheap, indexed lookup)
        $triggers = Trigger::enabled()
            ->where('event', $event)
            ->orderByPriority()
            ->get();

        // Wildcard matches — use cached collection of enabled wildcard triggers
        $wildcardTriggers = $this->getEnabledWildcardTriggers();

        foreach ($wildcardTriggers as $trigger) {
            if (WildcardMatcher::matches($trigger->event, $event)) {
                $exists = $triggers->firstWhere('id', $trigger->id);
                if (! $exists) {
                    $triggers->push($trigger);
                }
            }
        }

        // Sort by priority DESC, then by created_at ASC as a tiebreaker for equal priorities.
        // Add trigger id as final tiebreaker for fully deterministic ordering.
        return $triggers->sortBy(callback: fn (Trigger $t): array => [-$t->priority, $t->created_at?->timestamp ?? 0, $t->id], options: SORT_REGULAR)->values();
    }

    /**
     * Get all enabled triggers whose event pattern contains a wildcard.
     *
     * Results are cached with a TTL and invalidated on register / enable / disable.
     *
     * @return Collection<int, Trigger>
     */
    protected function getEnabledWildcardTriggers(): Collection
    {
        return Cache::remember(self::WILDCARD_TRIGGER_CACHE_KEY, self::TRIGGER_CACHE_TTL, fn (): Collection => Trigger::enabled()
            ->where('event', 'like', '%*%')
            ->orderByPriority()
            ->get());
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
                'id' => (string) Str::uuid(),
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
     */
    public function executeTrigger(Trigger $trigger, EventLog $log): void
    {
        $startTime = microtime(true);
        $log->status = EventLog::STATUS_DISPATCHED;
        $log->save();

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
            if (array_is_list($decoded)) {
                // List of entries — normalise each one
                return array_map(
                    fn (mixed $entry): mixed => is_array($entry) ? $entry : (string) $entry,
                    $decoded,
                );
            }

            // "classes" key → multiple actions with shared params (TriggerBuilder format).
            // Normalise into individual [class, params] entries so executeTrigger
            // can iterate uniformly.  Fixes #6: silent data loss when multiple
            // actions were saved with action params.
            if (isset($decoded['classes']) && is_array($decoded['classes'])) {
                $params = $decoded['params'] ?? [];

                return array_map(
                    fn (string $class): array => ['class' => $class, 'params' => $params],
                    $decoded['classes'],
                );
            }

            // Associative array → single action with class + params
            return [$decoded];
        }

        return [$action];
    }
}
