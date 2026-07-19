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
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $payload
     */
    public function matches(array $conditions, array $payload): bool;
}
