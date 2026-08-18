<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Exceptions;

/**
 * Base exception for all ZeroBoiler Events errors.
 *
 * Provides a common base type for catch-all error handling and
 * distinguishes events-package errors from framework exceptions.
 *
 * @see \ZeroBoiler\Events\Exceptions\TriggerNotFoundException
 * @see \ZeroBoiler\Events\Exceptions\ConditionEvaluationException
 * @see \ZeroBoiler\Events\Exceptions\ActionResolutionException
 * @see \ZeroBoiler\Events\Exceptions\SubscriptionException
 */
class EventException extends \RuntimeException
{
    /**
     * Create an EventException with a message and optional previous exception.
     *
     * @param  string  $message  Human-readable error description
     * @param  int  $code  Error code (default: 0)
     * @param  \Throwable|null  $previous  Previous exception for chaining
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
