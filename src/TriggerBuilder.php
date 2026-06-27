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
     */
    public function save(): Trigger
    {
        if (empty($this->event)) {
            throw new \InvalidArgumentException('Event name is required');
        }

        if (empty($this->action) && empty($this->actions)) {
            throw new \InvalidArgumentException('At least one action is required');
        }

        // Generate name from event if not provided
        if (empty($this->name)) {
            $this->name = $this->event.' Trigger';
        }

        $trigger = new Trigger([
            'id' => (string) Str::uuid(),
            'name' => $this->name,
            'event' => $this->event,
            'action' => $this->action ?: ($this->actions[0] ?? ''),
            'conditions' => $this->conditions,
            'async' => $this->async,
            'priority' => $this->priority,
            'enabled' => true,
        ]);
        $trigger->save();

        // Store additional actions in the action field as JSON if needed
        if (! empty($this->actions) && count($this->actions) > 1) {
            $trigger->action = json_encode($this->actions);
            $trigger->save();
        }

        return $trigger;
    }
}
