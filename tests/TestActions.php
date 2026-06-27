<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace App\Actions;

use ZeroBoiler\Events\Contracts\Triggerable;

class SendOrderNotification implements Triggerable
{
    public function handle(array $payload): void
    {
        // Handle order notification
    }
}

class LogOrderEvent implements Triggerable
{
    public function handle(array $payload): void
    {
        // Log order event
    }
}

class HighPriority implements Triggerable
{
    public function handle(array $payload): void
    {
        // Handle high priority action
    }
}

class LowPriority implements Triggerable
{
    public function handle(array $payload): void
    {
        // Handle low priority action
    }
}

class LogOrderCreated implements Triggerable
{
    public function handle(array $payload): void
    {
        // Log order created
    }
}
