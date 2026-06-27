<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Container\Container;
use ZeroBoiler\Events\Contracts\Triggerable;

class ActionResolver
{
    public function __construct(
        protected Container $app
    ) {}

    /**
     * Resolve a class FQN to a Triggerable instance.
     */
    public function resolve(string $class): Triggerable
    {
        // Check if class exists first
        if (! class_exists($class)) {
            throw new \InvalidArgumentException("Triggerable class {$class} does not exist");
        }

        return $this->app->make($class);
    }
}
