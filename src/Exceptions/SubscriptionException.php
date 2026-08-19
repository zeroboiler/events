<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Exceptions;

/**
 * Thrown when a webhook subscription operation fails.
 */
final class SubscriptionException extends EventException
{
    /**
     * @param  string  $message  Human-readable error description
     * @param  \Throwable|null  $previous  Previous exception for chaining
     *
     * @since 1.0.0
     */
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
