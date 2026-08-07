<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

final class EventManager
{
    use EscapesWildcardLike;
    use ManagesHistory;
    use ManagesSubscriptions;

    /**
     * Cache key for the enabled wildcard triggers collection.
     */
    protected const WILDCARD_TRIGGER_CACHE_KEY = 'zeroboiler:events:enabled_wildcard_triggers';

    /**
     * Default cache TTL in seconds (5 minutes).
     */
    protected const DEFAULT_TRIGGER_CACHE_TTL = 300;

    public function __construct(
        protected readonly ConditionEngine $conditionEngine,
        protected readonly ActionResolver $actionResolver,
        protected readonly Container $app,
    ) {}

    /**
     * Get the wildcard trigger cache TTL from config or use default.
     */
    protected function getTriggerCacheTtl(): int
    {
        $config = $this->app->get('config');
        assert($config instanceof \Illuminate\Contracts\Config\Repository);

        $ttl = $config->get('events.wildcard_cache_ttl', self::DEFAULT_TRIGGER_CACHE_TTL);

        return is_int($ttl) && $ttl > 0 ? $ttl : self::DEFAULT_TRIGGER_CACHE_TTL;
    }

    /**
     * Start building a new trigger.
     *
     * @param  string  $event  The event name (supports wildcards)
     */
    public function on(string $event): TriggerBuilder
    {
        $builder = $this->app->make(TriggerBuilder::class);
        assert($builder instanceof TriggerBuilder);
        $builder->on($event);

        return $builder;
    }

    /**
     * Alias for on().
     *
     * @param  string  $event  The event name (supports wildcards)
     */
    public function register(string $event): TriggerBuilder
    {
        return $this->on($event);
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
     * List triggers with optional filtering.
     *
     * @param  string|null  $event  Filter by event name (exact or wildcard)
     * @param  bool|null  $enabled  Filter by enabled status (true=enabled, false=disabled, null=all)
     * @param  int  $limit  Maximum number of results
     * @return Collection<int, Trigger>
     */
    public function listTriggers(?string $event = null, ?bool $enabled = null, int $limit = 100): Collection
    {
        $query = Trigger::query();

        if ($event !== null && $event !== '') {
            $likePattern = $this->wildcardToLike($event);
            if ($likePattern !== null) {
                $query->where('event', 'like', $likePattern);
            } else {
                $query->where('event', $event);
            }
        }

        if ($enabled !== null) {
            $query->where('enabled', $enabled);
        }

        return $query->orderByPriority()->limit($limit)->get();
    }

    /**
     * Get a trigger by ID.
     */
    public function getTrigger(string $triggerId): ?Trigger
    {
        return Trigger::find($triggerId);
    }

    /**
     * Delete a trigger by ID.
     *
     * Returns true if the trigger was found and deleted, false otherwise.
     * Automatically invalidates the trigger cache.
     */
    public function deleteTrigger(string $triggerId): bool
    {
        $trigger = Trigger::find($triggerId);

        if ($trigger === null) {
            return false;
        }

        $trigger->delete();
        $this->invalidateTriggerCache();

        return true;
    }

    /**
     * Enable a trigger by ID.
     *
     * @param  string  $triggerId  The UUID of the trigger to enable
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
     *
     * @param  string  $triggerId  The UUID of the trigger to disable
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
     * Fire an event and dispatch all matching triggers.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \InvalidArgumentException If the event name is empty
     * @throws \Throwable If a synchronous trigger action fails (re-thrown after logging)
     */
    public function fire(string $event, array $payload = []): void
    {
        if ($event === '' || $event === '0') {
            throw new \InvalidArgumentException('Event name cannot be empty.');
        }

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
     *
     * Constructs the event name as `{modelClass}.{action}` (e.g. `App\\Models\\Order.created`).
     * The model's attributes are automatically flattened into the payload root so conditions
     * can reference them directly (e.g. `status == 'active'` instead of `model.status == 'active'`).
     * The original model object and metadata are also included in the payload.
     *
     * @param  string  $modelClass  The fully-qualified model class name
     * @param  string  $action  The model action (e.g., 'created', 'updated', 'deleted')
     * @param  object  $model  The model instance (must have attributesToArray or toArray method)
     *
     * @throws \InvalidArgumentException If the model class or action is empty
     */
    public function fireModel(string $modelClass, string $action, object $model): void
    {
        if ($modelClass === '' || $modelClass === '0') {
            throw new \InvalidArgumentException('Model class name cannot be empty.');
        }

        if ($action === '' || $action === '0') {
            throw new \InvalidArgumentException('Model action cannot be empty.');
        }

        $event = $modelClass.'.'.$action;

        // Flatten model attributes into the payload root so the condition
        // engine can access them directly (e.g. condition "status == 'active'"
        // instead of "model.status == 'active'").
        $modelData = [];
        if (method_exists($model, 'attributesToArray')) {
            /** @var array<string, mixed> $modelData */
            $modelData = $model->attributesToArray();
        } elseif (method_exists($model, 'toArray')) {
            /** @var array<string, mixed> $modelData */
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
     * Get all triggers matching an event (including wildcards).
     *
     * Uses a cached collection of enabled triggers with wildcards to avoid
     * loading all triggers on every fire() call. Exact (non-wildcard) matches
     * are queried directly from the DB for freshness.
     *
     * Duplicate triggers (exact + wildcard matching same DB row) are
     * deduplicated using an O(1) id set instead of O(n) firstWhere.
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

        // Build an O(1) lookup set of already-collected trigger IDs
        // to avoid O(n) firstWhere on each wildcard iteration.
        /** @var array<string, true> $collectedIds */
        $collectedIds = [];
        foreach ($triggers as $t) {
            $collectedIds[$t->id] = true;
        }

        // Wildcard matches — use cached collection of enabled wildcard triggers
        $wildcardTriggers = $this->getEnabledWildcardTriggers();

        foreach ($wildcardTriggers as $trigger) {
            if (! isset($collectedIds[$trigger->id]) && WildcardMatcher::matches($trigger->event, $event)) {
                $triggers->push($trigger);
                $collectedIds[$trigger->id] = true;
            }
        }

        // Sort by priority DESC, then by created_at ASC as a tiebreaker for equal priorities.
        // Add trigger id as final tiebreaker for fully deterministic ordering.
        return $triggers->sortBy(
            callback: fn (Trigger $t): array => [
                -$t->priority,
                $t->created_at?->timestamp ?? 0,
                $t->id,
            ],
            options: SORT_REGULAR,
            descending: false,
        )->values();
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
        $cached = Cache::get(self::WILDCARD_TRIGGER_CACHE_KEY);

        if ($cached instanceof Collection) {
            return $cached;
        }

        $result = Trigger::enabled()
            ->where('event', 'like', '%*%')
            ->orderByPriority()
            ->get();

        assert($result instanceof Collection);

        Cache::put(self::WILDCARD_TRIGGER_CACHE_KEY, $result, $this->getTriggerCacheTtl());

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
     *
     * @throws \Throwable If the action handler fails (re-thrown after logging and updating log status)
     */
    public function executeTrigger(Trigger $trigger, EventLog $log): void
    {
        $startTime = microtime(true);
        $log->status = EventLog::STATUS_DISPATCHED;
        $log->save();

        try {
            $actions = $this->parseActions($trigger->action);
            $basePayload = is_array($log->payload) ? $log->payload : [];

            foreach ($actions as $entry) {
                // parseActions returns a normalised array where each entry
                // is either a class name string or an array with 'class'
                // and optional 'params'.
                if (is_array($entry)) {
                    $actionClass = is_string($entry['class'] ?? null) ? $entry['class'] : '';
                    $actionParams = is_array($entry['params'] ?? null) ? $entry['params'] : [];
                } else {
                    $actionClass = (string) $entry;
                    $actionParams = [];
                }

                $handler = $this->actionResolver->resolve($actionClass);

                // Merge trigger-level params (e.g. webhook URL) into the
                // event payload so the action has everything it needs.
                $payload = $actionParams !== [] ? array_merge($actionParams, $basePayload) : $basePayload;

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
     * - An array with 'class' and 'params':  [["class" => "...", "params" => [...]]]
     *
     * Supports:
     * - Single class name string:  "App\\Actions\\Foo"
     * - JSON array of class names:  ["App\\Actions\\Foo", "App\\Actions\\Bar"]
     * - JSON object with class + params:  {"class": "...", "params": {...}}
     * - JSON object with classes + params:  {"classes": [...], "params": {...}}
     * - JSON array of objects:  [{"class": "...", "params": {...}}, ...]
     *
     * @return list<string|array{class: string, params?: array<string, mixed>}>
     */
    protected function parseActions(string $action): array
    {
        // Empty action string — return empty list
        if ($action === '' || $action === '0') {
            return [];
        }

        // Try to decode as JSON first
        $decoded = json_decode($action, true);

        if (is_array($decoded)) {
            // Handle {"classes": [...], "params": {...}} — multiple actions with shared params
            if (isset($decoded['classes']) && is_array($decoded['classes'])) {
                $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

                return array_map(
                    fn (mixed $cls): array => [
                        'class' => is_string($cls) ? $cls : '',
                        'params' => $params,
                    ],
                    $decoded['classes'],
                );
            }

            // Sequential list → normalise each entry
            if (array_is_list($decoded)) {
                // List of entries — normalise each one
                return array_map(
                    fn (mixed $entry): string|array => is_array($entry) ? $entry : (string) $entry,
                    $decoded,
                );
            }

            // Associative array → single action with class + params
            return [$decoded];
        }

        return [$action];
    }
}
