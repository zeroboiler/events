<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Container\Container;
use ZeroBoiler\Events\Contracts\Triggerable;

/**
 * Resolves a class FQN string to a Triggerable instance from the container.
 *
 * Used by EventManager to instantiate action handlers before dispatch.
 * Validates that the resolved class implements the Triggerable contract.
 *
 * @see \ZeroBoiler\Events\Contracts\Triggerable
 */
final class ActionResolver
{
    public function __construct(
        protected readonly Container $app,
    ) {}

    /**
     * Resolve a class FQN to a Triggerable instance.
     *
     * @throws \InvalidArgumentException if the class does not exist or does not implement Triggerable
     */
    public function resolve(string $class): Triggerable
    {
        // Check if class exists first
        if (! class_exists($class)) {
            throw new \InvalidArgumentException("Triggerable class {$class} does not exist");
        }

        $instance = $this->app->make($class);

        if (! $instance instanceof Triggerable) {
            throw new \InvalidArgumentException("Class {$class} must implement ".Triggerable::class);
        }

        return $instance;
    }
}
