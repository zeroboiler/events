<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Contracts;

interface ConditionEngineContract
{
    /**
     * Evaluate conditions against a payload.
     *
     * All conditions must match (AND logic) for the result to be true.
     *
     * @param  array<string, mixed>  $conditions  Field => expected value or operator array
     * @param  array<string, mixed>  $payload  The event payload to evaluate against
     * @return bool True if all conditions match, false otherwise
     *
     * @since 1.0.0
     */
    public function matches(array $conditions, array $payload): bool;
}
