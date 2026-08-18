<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Exceptions;

/**
 * Thrown when an action handler class cannot be resolved or does not implement Triggerable.
 */
final class ActionResolutionException extends EventException
{
    /**
     * @param  string  $class  The action class FQN that failed resolution
     * @param  string  $reason  Human-readable reason for the failure
     */
    public function __construct(string $class, string $reason = '')
    {
        $message = $reason !== ''
            ? "Failed to resolve action '{$class}': {$reason}"
            : "Failed to resolve action '{$class}'";

        parent::__construct($message);
    }
}
