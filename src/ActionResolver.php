<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Container\Container;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Exceptions\ActionResolutionException;

/**
 * Resolves a class FQN string to a Triggerable instance from the container.
 *
 * Used by EventManager to instantiate action handlers before dispatch.
 * Validates that the resolved class implements the Triggerable contract.
 *
 * @see \ZeroBoiler\Events\Contracts\Triggerable
 *
 * @since 1.0.0
 */
final class ActionResolver
{
    public function __construct(
        private readonly Container $app,
    ) {}

    /**
     * Resolve a class FQN to a Triggerable instance.
     *
     * @throws \ZeroBoiler\Events\Exceptions\ActionResolutionException if the class does not exist or does not implement Triggerable
     *
     * @since 1.0.0
     */
    public function resolve(string $class): Triggerable
    {
        // Check if class exists first
        if (! class_exists($class)) {
            throw new ActionResolutionException($class, 'Class does not exist');
        }

        $instance = $this->app->make($class);

        if (! $instance instanceof Triggerable) {
            throw new ActionResolutionException(
                $class,
                'Class must implement ' . Triggerable::class,
            );
        }

        return $instance;
    }
}
