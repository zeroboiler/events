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
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void;
}
