<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use ZeroBoiler\Events\Models\Trigger;

class TriggerBuilder
{
    protected string $name = '';

    protected string $event = '';

    /** @var array<int, string> */
    protected array $actions = [];

    /** @var array<string, mixed> */
    protected array $conditions = [];

    protected bool $async = false;

    protected int $priority = 0;

    /** @var array<string, mixed> */
    protected array $actionParams = [];

    public function __construct(
        protected EventManager $eventManager
    ) {}

    /**
     * Set the trigger name.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the event name.
     */
    public function on(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Add an action handler class.
     *
     * Multiple calls accumulate into a list of actions, so
     * ```
     * ->action(Foo::class)->action(Bar::class)
     * ```
     * results in both Foo and Bar being registered (#6).
     */
    public function action(string $class): self
    {
        $this->actions[] = $class;

        return $this;
    }

    /**
     * Set multiple action handler classes.
     *
     * @param  array<int, string>  $classes
     */
    public function actions(array $classes): self
    {
        $this->actions = $classes;

        return $this;
    }

    /**
     * Resolve the final action classes list.
     *
     * action() calls accumulate into $actions. actions() replaces the list.
     * If both were used, merge them (actions() list first, then any action()
     * calls that aren't already in the list).
     *
     * @return list<string>
     */
    private function resolveActions(): array
    {
        return array_values(array_unique($this->actions, SORT_REGULAR));
    }

    /**
     * Set the conditions.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function when(array $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Set whether the trigger should be dispatched asynchronously.
     */
    public function async(bool $async = true): self
    {
        $this->async = $async;

        return $this;
    }

    /**
     * Set the priority (higher values execute first).
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
     */
    public function save(): Trigger
    {
        if ($this->event === '' || $this->event === '0') {
            throw new \InvalidArgumentException('Event name is required');
        }

        if ($this->actions === []) {
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
