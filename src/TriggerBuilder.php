<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Support\Str;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Fluent builder for creating event triggers.
 *
 * Usage:
 * ```php
 * EventManager::on('order.placed')
 *     ->name('Send Notification')
 *     ->action(SendNotification::class)
 *     ->when(['amount' => ['>', 100]])
 *     ->async()
 *     ->priority(10)
 *     ->save();
 * ```
 *
 * @since 1.0.0
 */
final class TriggerBuilder
{
    protected string $name = '';

    protected string $event = '';

    protected string $action = '';

    /** @var array<int, string> */
    protected array $actions = [];

    /** @var array<string, mixed> */
    protected array $conditions = [];

    protected bool $async = false;

    protected int $priority = 0;

    /** @var array<string, mixed> */
    protected array $actionParams = [];

    public function __construct(
        private readonly EventManager $eventManager,
    ) {}

    /**
     * Set the trigger name.
     *
     * @since 1.0.0
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the event name.
     *
     * @since 1.0.0
     */
    public function on(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Set the action handler class.
     *
     * @since 1.0.0
     */
    public function action(string $class): self
    {
        $this->action = $class;

        return $this;
    }

    /**
     * Set multiple action handler classes.
     *
     * @param  array<int, string>  $classes
     * @throws \InvalidArgumentException If any class name is not a non-empty string
     *
     * @since 1.0.0
     */
    public function actions(array $classes): self
    {
        foreach ($classes as $cls) {
            if (! is_string($cls) || $cls === '' || $cls === '0') {
                throw new \InvalidArgumentException('Each action class must be a non-empty string.');
            }
        }

        $this->actions = $classes;

        return $this;
    }

    /**
     * Resolve the final action classes list, merging single action() and actions() calls.
     *
     * If both were called, the single action is prepended to the list to avoid
     * silently discarding it (BUG-2 fix). Deduplication ensures the same class
     * doesn't appear twice (preserves first-occurrence order).
     *
     * @return list<string|array{class: string, params: array<string, mixed>}> Resolved action class FQNs or structured action entries with params
     *
     * @internal Not part of the public API.
     */
    private function resolveActions(): array
    {
        $all = $this->actions;

        // If both action() and actions() were called, merge them.
        // Prepend the single action only if it's not already in the list.
        if ($this->action !== '' && $this->action !== '0' && ! in_array($this->action, $all, true)) {
            array_unshift($all, $this->action);
        }

        // Deduplicate while preserving insertion order (first occurrence wins).
        // This prevents duplicate dispatch when action() and actions() both
        // contain the same class, or when actions() contains duplicates.
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<string> $unique */
        $unique = [];
        foreach ($all as $cls) {
            if (! isset($seen[$cls])) {
                $seen[$cls] = true;
                $unique[] = $cls;
            }
        }

        return $unique;
    }

    /**
     * Set the conditions.
     *
     * @param  array<string, mixed>  $conditions
     *
     * @since 1.0.0
     */
    public function when(array $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Set whether the trigger should be dispatched asynchronously.
     *
     * @since 1.0.0
     */
    public function async(bool $async = true): self
    {
        $this->async = $async;

        return $this;
    }

    /**
     * Set the priority (higher values execute first).
     *
     * @since 1.0.0
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Set action handler parameters (e.g. webhook URL).
     *
     * These are merged into the action handler's constructor args
     * when the trigger is dispatched.
     *
     * @param  array<string, mixed>  $params
     *
     * @since 1.0.0
     */
    public function actionParams(array $params): self
    {
        $this->actionParams = $params;

        return $this;
    }

    /**
     * Save the trigger to the database.
     *
     * Uses a single INSERT to avoid the race condition where a double
     * INSERT→UPDATE could collide on the UUID primary key.
     *
     * @throws \InvalidArgumentException If event name is empty or no action is provided
     * @throws \JsonException If JSON encoding of action string fails
     *
     * @since 1.0.0
     */
    public function save(): Trigger
    {
        if ($this->event === '' || $this->event === '0') {
            throw new \InvalidArgumentException('Event name is required');
        }

        if (($this->action === '' || $this->action === '0') && $this->actions === []) {
            throw new \InvalidArgumentException('At least one action is required');
        }

        // Generate name from event if not provided
        if ($this->name === '' || $this->name === '0') {
            $this->name = $this->event.' Trigger';
        }

        // Resolve the final list of action classes, merging action() and actions().
        $resolvedActions = $this->resolveActions();

        // Build the final action string.
        // If action params are set, encode them with the action class(es).
        // For multiple actions, use "classes" key instead of "class" (#684):
        // Single:  {"class": "Foo", "params": {...}}
        // Multiple: {"classes": ["Foo", "Bar"], "params": {...}}
        $actionString = match (true) {
            $this->actionParams !== [] && count($resolvedActions) > 1 => json_encode([
                'classes' => $resolvedActions,
                'params' => $this->actionParams,
            ], \JSON_THROW_ON_ERROR),
            $this->actionParams !== [] => json_encode([
                'class' => $resolvedActions[0] ?? '',
                'params' => $this->actionParams,
            ], \JSON_THROW_ON_ERROR),
            count($resolvedActions) > 1 => json_encode($resolvedActions, \JSON_THROW_ON_ERROR),
            $resolvedActions !== [] => $resolvedActions[0],
            default => '',
        };

        $trigger = new Trigger([
            'id' => (string) Str::uuid(),
            'name' => $this->name,
            'event' => $this->event,
            'action' => $actionString,
            'conditions' => $this->conditions,
            'async' => $this->async,
            'priority' => $this->priority,
            'enabled' => true,
        ]);
        $trigger->save();

        // Invalidate the EventManager trigger cache so the new trigger
        // is visible immediately.
        $this->eventManager->invalidateTriggerCache();

        return $trigger;
    }
}
