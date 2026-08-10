<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Contracts;

interface Triggerable
{
    /**
     * Handle the event payload.
     *
     * Called by the EventManager when a trigger is dispatched.
     * Implementations should perform their side-effect (e.g. send notification,
     * dispatch webhook, update state) within this method.
     *
     * @param  array<string, mixed>  $payload  The event payload (may include action params merged in)
     *
     * @throws \Throwable Implementations may throw to indicate failure
     */
    public function handle(array $payload): void;
}
