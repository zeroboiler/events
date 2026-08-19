<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Exceptions;

/**
 * Thrown when condition evaluation encounters an unrecoverable error.
 */
final class ConditionEvaluationException extends EventException
{
    /**
     * @param  string  $field  The condition field that caused the error
     * @param  string  $reason  Human-readable reason for the failure
     *
     * @since 1.0.0
     */
    public function __construct(string $field, string $reason)
    {
        parent::__construct("Condition evaluation failed for field '{$field}': {$reason}");
    }
}
