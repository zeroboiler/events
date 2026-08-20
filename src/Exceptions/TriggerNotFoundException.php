<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Exceptions;

/**
 * Thrown when a trigger lookup fails (e.g., invalid ID, trigger deleted).
 */
final class TriggerNotFoundException extends EventException
{
    /**
     * @param  string  $triggerId  The trigger ID that was not found
     *
     * @since 1.0.0
     */
    public function __construct(string $triggerId)
    {
        parent::__construct("Trigger not found: {$triggerId}");
    }
}

