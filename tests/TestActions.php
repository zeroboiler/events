<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace App\Actions;

use ZeroBoiler\Events\Contracts\Triggerable;

final class SendOrderNotification implements Triggerable
{
    public function handle(array $payload): void
    {
        // Handle order notification
    }
}

final class LogOrderEvent implements Triggerable
{
    public function handle(array $payload): void
    {
        // Log order event
    }
}

final class HighPriority implements Triggerable
{
    public function handle(array $payload): void
    {
        // Handle high priority action
    }
}

final class LowPriority implements Triggerable
{
    public function handle(array $payload): void
    {
        // Handle low priority action
    }
}

final class LogOrderCreated implements Triggerable
{
    public function handle(array $payload): void
    {
        // Log order created
    }
}
