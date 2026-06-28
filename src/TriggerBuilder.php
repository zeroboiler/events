<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Support\Str;
use ZeroBoiler\Events\Models\Trigger;

class TriggerBuilder
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
     * Set the action handler class.
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
     */
    public function actions(array $classes): self
    {
        $this->actions = $classes;

        return $this;
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

        if (($this->action === '' || $this->action === '0') && $this->actions === []) {
            throw new \InvalidArgumentException('At least one action is required');
        }

        // Generate name from event if not provided
        if ($this->name === '' || $this->name === '0') {
            $this->name = $this->event.' Trigger';
        }

        // Build the final action string once — no second save() needed
        $actionString = $this->action;
        if ($this->actions !== [] && count($this->actions) > 1) {
            $actionString = json_encode($this->actions);
        } elseif (($actionString === '' || $actionString === '0') && $this->actions !== []) {
            $actionString = $this->actions[0];
        }

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
