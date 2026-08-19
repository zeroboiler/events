<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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

/**
 * Central event orchestrator for the ZeroBoiler Events system.
 *
 * Manages trigger registration, event firing, condition evaluation,
 * action dispatch (sync/async), wildcard matching with caching, webhook
 * subscription lifecycle, event history, and statistics.
 *
 * Resolved as a singleton from the service container. Accessible via
 * the `EventManager` facade or direct injection.
 *
 * @property-read \Illuminate\Container\Container $app
 *
 * @see \ZeroBoiler\Events\Concerns\ManagesHistory Provides event history, statistics, and log purge operations
 * @see \ZeroBoiler\Events\Concerns\ManagesSubscriptions Provides webhook subscription management operations
 * @see \ZeroBoiler\Events\Concerns\EscapesWildcardLike Provides wildcard-to-SQL-LIKE pattern conversion
 *
 * @see \ZeroBoiler\Events\Facades\EventManager
 *
 * @since 1.0.0
 */
final class EventManager
{
    use EscapesWildcardLike;
    use ManagesHistory;
    use ManagesSubscriptions;

    /**
     * Cache key for the enabled wildcard triggers collection.
     */
    private const WILDCARD_TRIGGER_CACHE_KEY = 'zeroboiler:events:enabled_wildcard_triggers';

    /**
     * Default cache TTL in seconds (5 minutes).
     */
    private const DEFAULT_TRIGGER_CACHE_TTL = 300;

    public function __construct(
        private readonly ConditionEngine $conditionEngine,
        private readonly ActionResolver $actionResolver,
        private readonly Container $app,
    ) {}

    /**
     * Get the config repository from the container with type narrowing.
     *
     * @internal Not part of the public API.
     *
     * @since 1.0.0
     */
    protected function getConfig(): ConfigRepository
    {
        $config = $this->app->get('config');

        if ($config instanceof ConfigRepository) {
            return $config;
        }

        throw new \RuntimeException('Config repository not available in the container.');
    }

    /**
     * Get the application container.
     *
     * Exposed as public so that collaborators (e.g., SubscriptionBuilder)
     * can access the container without reaching into protected properties.
     *
     * @since 1.0.0
     */
    public function container(): Container
    {
        return $this->app;
    }

    /**
     * Get the maximum allowed payload size in bytes from config.
     *
     * Returns 0 to disable the size check.
     *
     * @internal Not part of the public API.
     */
    protected function getPayloadMaxBytes(): int
    {
        $max = $this->getConfig()->get('events.payload_max_bytes', 1_048_576);

        if (is_int($max) && $max >= 0) {
            return $max;
        }

        if (is_numeric($max) && (int) $max >= 0) {
            return (int) $max;
        }

        return 1_048_576;
    }

    /**
     * Get the wildcard trigger cache TTL from config or use default.
     *
     * Returns 0 when `events.wildcard_cache_ttl` is explicitly set to 0,
     * which disables caching (each fire() call queries the DB).
     * Returns the default TTL (300s) for any non-integer, null, or
     * negative value.
     *
     * @internal Not part of the public API.
     */
    protected function getTriggerCacheTtl(): int
    {
        $ttl = $this->getConfig()->get('events.wildcard_cache_ttl', self::DEFAULT_TRIGGER_CACHE_TTL);

        // Accept 0 (int or string) to disable caching
        if ($ttl === 0 || $ttl === '0') {
            return 0;
        }

        if (is_int($ttl) && $ttl > 0) {
            return $ttl;
        }

        // env() always returns string|null, so handle numeric strings
        if (is_numeric($ttl) && (int) $ttl > 0) {
            return (int) $ttl;
        }

        return self::DEFAULT_TRIGGER_CACHE_TTL;
    }

    /**
     * Start building a new trigger.
     *
     * @param  string  $event  The event name (supports wildcards)
     *
     * @since 1.0.0
     */
    public function on(string $event): TriggerBuilder
    {
        $builder = $this->app->make(TriggerBuilder::class);

        if (! $builder instanceof TriggerBuilder) {
            throw new \RuntimeException('TriggerBuilder could not be resolved from the container.');
        }

        $builder->on($event);

        return $builder;
    }

    /**
     * Alias for on().
     *
     * @param  string  $event  The event name (supports wildcards)
     *
     * @since 1.0.0
     */
    public function register(string $event): TriggerBuilder
    {
        return $this->on($event);
    }

    /**
     * Invalidate the trigger cache.
     *
     * Call after register / unregister / enable / disable.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function invalidateTriggerCache(): void
    {
        Cache::forget(self::WILDCARD_TRIGGER_CACHE_KEY);
    }

    /**
     * Check if the event system is globally disabled.
     *
     * Reads the `events.disabled` config value.
     *
     * @since 1.0.0
     */
    public function isDisabled(): bool
    {
        return $this->getConfig()->get('events.disabled', false) === true;
    }

    /**
     * Globally enable or disable the event system at runtime.
     *
     * This only affects the in-memory config and does not persist
     * across requests. Use `EVENTS_DISABLED=true` in .env for
     * persistent disable.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function setEnabled(bool $enabled): void
    {
        $this->getConfig()->set('events.disabled', ! $enabled);
    }

    /**
     * List triggers with optional filtering.
     *
     * @param  string|null  $event  Filter by event name (exact or wildcard)
     * @param  bool|null  $enabled  Filter by enabled status (true=enabled, false=disabled, null=all)
     * @param  int  $limit  Maximum number of results
     * @return Collection<int, Trigger>
     *
     * @since 1.0.0
     */
    public function listTriggers(?string $event = null, ?bool $enabled = null, int $limit = 100): Collection
    {
        $query = Trigger::query();

        if ($event !== null && $event !== '' && $event !== '0') {
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

        return $query->orderByPriority()
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get a trigger by ID.
     *
     * @param  string  $triggerId  The UUID of the trigger
     *
     * @since 1.0.0
     */
    public function getTrigger(string $triggerId): ?Trigger
    {
        if ($triggerId === '' || $triggerId === '0') {
            return null;
        }

        return Trigger::find($triggerId);
    }

    /**
     * Delete a trigger by ID.
     *
     * Returns true if the trigger was found and deleted, false otherwise.
     * Automatically invalidates the trigger cache.
     *
     * @param  string  $triggerId  The UUID of the trigger to delete
     *
     * @since 1.0.0
     */
    public function deleteTrigger(string $triggerId): bool
    {
        if ($triggerId === '' || $triggerId === '0') {
            return false;
        }

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
     *
     * @since 1.0.0
     */
    public function enable(string $triggerId): bool
    {
        if ($triggerId === '' || $triggerId === '0') {
            return false;
        }

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
     *
     * @since 1.0.0
     */
    public function disable(string $triggerId): bool
    {
        if ($triggerId === '' || $triggerId === '0') {
            return false;
        }

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
     * @param  bool  $async  When true, forces all matching triggers to be dispatched asynchronously via queue.
     *                       When false, triggers are dispatched according to their individual `async` setting.
     *
     * @return void
     *
     * @throws \InvalidArgumentException If the event name is empty or payload exceeds size limit
     * @throws \Throwable If a synchronous trigger action fails (re-thrown after logging)
     *
     * @since 1.0.0
     */
    public function fire(string $event, array $payload = [], bool $async = false): void
    {
        if ($event === '' || $event === '0') {
            throw new \InvalidArgumentException('Event name cannot be empty.');
        }

        // Guard against unreasonably large payloads that could cause OOM
        // when serialized to JSON for storage or queue dispatch.
        // 1 MB is a generous upper bound for event payloads.
        try {
            $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Event payload is not JSON-encodable: '.$e->getMessage(), 0, $e);
        }

        $maxBytes = $this->getPayloadMaxBytes();
        if ($maxBytes > 0 && strlen((string) $encoded) > $maxBytes) {
            $maxMb = round($maxBytes / 1_048_576, 1);
            throw new \InvalidArgumentException("Event payload exceeds the maximum allowed size ({$maxMb} MB).");
        }

        // Global disable check — allows maintenance-mode-like suppression
        if ($this->isDisabled()) {
            return;
        }

        $triggers = $this->getMatchingTriggers($event);

        foreach ($triggers as $trigger) {
            if (! $this->shouldDispatch($trigger, $payload)) {
                continue;
            }

            $this->dispatchTrigger($trigger, $event, $payload, forceAsync: $async);
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
     * If the model object has neither `attributesToArray()` nor `toArray()` method,
     * the event fires with only the metadata keys (`model`, `model_class`, `action`).
     *
     * @param  string  $modelClass  The fully-qualified model class name
     * @param  string  $action  The model action (e.g., 'created', 'updated', 'deleted')
     * @param  object  $model  The model instance (should implement attributesToArray or toArray for payload flattening)
     *
     * @return void
     *
     * @throws \InvalidArgumentException If the model class or action is empty
     *
     * @since 1.0.0
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
     * Register event-related scheduled tasks with the given scheduler.
     *
     * Delegates to EventScheduler::register(). This method exists as a
     * convenience facade entry point so that consumers can call
     * `EventManager::registerScheduler($schedule)` or
     * `EventManagerFacade::registerScheduler($schedule)`.
     *
     * @param  Schedule  $schedule  The Laravel scheduler instance
     * @return void
     *
     * @throws \RuntimeException If EventScheduler cannot be resolved from the container
     *
     * @since 1.0.0
     */
    public function registerScheduler(Schedule $schedule): void
    {
        $scheduler = $this->app->make(EventScheduler::class);

        if (! $scheduler instanceof EventScheduler) {
            throw new \RuntimeException('EventScheduler could not be resolved from the container.');
        }

        $scheduler->register($schedule);
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
     *
     * @internal Not part of the public API. Use fire() to trigger dispatch.
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
        // Note: sortBy callback returns negative priority for DESC ordering in ASC sort.
        $sorted = $triggers->sortBy(
            fn (Trigger $t): array => [
                -$t->priority,
                $t->created_at?->getTimestamp() ?? 0,
                $t->id,
            ],
            SORT_NUMERIC,
        );

        return $sorted->values();
    }

    /**
     * Get all enabled triggers whose event pattern contains a wildcard.
     *
     * Results are cached with a TTL and invalidated on register / enable / disable.
     *
     * @return Collection<int, Trigger>
     *
     * @internal Not part of the public API.
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

        Cache::put(self::WILDCARD_TRIGGER_CACHE_KEY, $result, $this->getTriggerCacheTtl());

        return $result;
    }

    /**
     * Check if a trigger should be dispatched based on conditions.
     *
     * @param  array<string, mixed>  $payload
     *
     * @internal Not part of the public API.
     */
    protected function shouldDispatch(Trigger $trigger, array $payload): bool
    {
        if ($trigger->conditions === []) {
            return true;
        }

        return $this->conditionEngine->matches($trigger->conditions, $payload);
    }

    /**
     * Dispatch a trigger (sync or async).
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $forceAsync  When true, forces async dispatch regardless of the trigger's async setting.
     *
     * @internal Not part of the public API. Use fire() or executeTrigger() instead.
     */
    protected function dispatchTrigger(Trigger $trigger, string $event, array $payload, bool $forceAsync = false): void
    {
        $shouldAsync = $forceAsync || $trigger->async;

        if ($shouldAsync) {
            // Create the EventLog inside the job so that if the job never
            // runs (queue down, Redis flushed, etc.) no orphaned log entry
            // is left behind in the database. See bug #632.
            //
            // Sanitize the payload for queue serialization: non-scalar values
            // (objects, resources, closures) cannot be serialized by Redis/database
            // queue drivers and would cause SilentJobFailed exceptions. Model
            // objects from fireModel() are common non-serializable payloads.
            $queuePayload = $this->sanitizePayloadForQueue($payload);

            Queue::push(new DispatchTriggerJob(
                $trigger->id,
                $event,
                $queuePayload,
                $this->app,
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
     *
     * @since 1.0.0
     */
    public function executeTrigger(Trigger $trigger, EventLog $log): void
    {
        $startTime = microtime(true);
        $log->status = EventLog::STATUS_DISPATCHED;
        $log->save();

        $basePayload = is_array($log->payload) ? $log->payload : [];

        try {
            $actions = $this->parseActions($trigger->action);

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
                'payload_keys' => array_keys($basePayload),
            ]);

            throw $e;
        }
    }

    /**
     * Parse action string into normalised array of action entries.
     *
     * Each entry is either:
     * - A class name string (simple format):  `["App\\Actions\\Foo"]`
     * - An array with 'class' and optional 'params':  `[["class" => "...", "params" => [...]]]`
     *
     * Supports multiple input formats:
     * - Single class name string:  `"App\\Actions\\Foo"`
     * - JSON array of class names:  `["App\\Actions\\Foo", "App\\Actions\\Bar"]`
     * - JSON object with class + params:  `{"class": "...", "params": {...}}`
     * - JSON object with classes + params:  `{"classes": [...], "params": {...}}`
     * - JSON array of objects:  `[{"class": "...", "params": {...}}, ...]`
     *
     * @return list<string|array{class: string, params: array<string, mixed>}>
     *
     * @internal Not part of the public API.
     */
    protected function parseActions(string $action): array
    {
        $trimmed = trim($action);
        if ($trimmed === '' || $trimmed === '0') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            // {"classes": [...], "params": {...}} — multiple actions with shared params
            if (isset($decoded['classes']) && is_array($decoded['classes'])) {
                $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

                /** @var list<array{class: string, params: array<string, mixed>}> */
                return array_map(
                    static fn (mixed $cls): array => [
                        'class' => is_string($cls) ? $cls : '',
                        'params' => $params,
                    ],
                    $decoded['classes'],
                );
            }

            // Sequential list → normalise each entry
            if (array_is_list($decoded)) {
                /** @var list<string|array{class: string, params: array<string, mixed>}> */
                return array_map(
                    static fn (mixed $entry): string|array => is_string($entry)
                        ? $entry
                        : (is_array($entry) ? $entry : (string) $entry),
                    $decoded,
                );
            }

            // Associative array → single action with class + params
            return [$decoded];
        }

        return [$action];
    }

    /**
     * Sanitize a payload array for queue serialization.
     *
     * Removes non-serializable values (objects, resources, closures) from the
     * payload so that `DispatchTriggerJob` can be safely serialized by
     * Redis/database queue drivers. Scalar values (string, int, float, bool,
     * null) and arrays containing only serializable values are preserved.
     *
     * Non-serializable keys are replaced with a string placeholder indicating
     * the original type, so the receiving action handler can detect that
     * a value was stripped.
     *
     * @param  array<string, mixed>  $payload
     * @return array<mixed, mixed> Array with non-serializable values replaced by type placeholders.
     *                               Nested arrays may have integer keys from recursive calls.
     *
     * @internal Not part of the public API.
     */
    protected function sanitizePayloadForQueue(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $nested = $this->sanitizePayloadForQueue($value);
                $result[$key] = $nested;
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } else {
                // Non-serializable value: replace with type placeholder
                $result[$key] = '[stripped:'.get_debug_type($value).']';
            }
        }

        return $result;
    }
}
