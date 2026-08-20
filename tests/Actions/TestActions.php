<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests\Actions;

use ZeroBoiler\Events\Contracts\Triggerable;

/**
 * Test action implementations for the events test suite.
 *
 * All classes implement the Triggerable contract and are registered
 * in the test application container so they can be resolved by
 * ActionResolver during integration tests.
 */

/**
 * Test action that handles order placement notifications.
 * Tracks call state for test assertions.
 */
final class SendOrderNotification implements Triggerable
{
    /** Track whether handle() was called for verification in tests. */
    public bool $handled = false;

    /** @var array<string, mixed> Payload received by handle() */
    public array $receivedPayload = [];

    #[\Override]
    public function handle(array $payload): void
    {
        $this->handled = true;
        $this->receivedPayload = $payload;
    }

    /**
     * Reset tracking state between tests.
     */
    public function reset(): void
    {
        $this->handled = false;
        $this->receivedPayload = [];
    }
}

/**
 * Test action for logging order events. Intentionally no-op.
 */
final class LogOrderEvent implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        // Log order event — intentionally no-op for tests
    }
}

/**
 * Test action for high-priority triggers. Intentionally no-op.
 */
final class HighPriority implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        // Handle high priority action
    }
}

/**
 * Test action for low-priority triggers. Intentionally no-op.
 */
final class LowPriority implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        // Handle low priority action
    }
}

/**
 * Test action for order creation logging. Intentionally no-op.
 */
final class LogOrderCreated implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        // Log order created
    }
}

/**
 * Test action that does nothing. Used for testing action resolution.
 */
final class NullAction implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        // Intentionally empty — test action that does nothing
    }
}

/**
 * Action that throws an exception for testing failure handling.
 */
final class FailingAction implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        throw new \RuntimeException('Action intentionally failed for testing.');
    }
}

/**
 * Action that records call count for testing multiple dispatches.
 */
final class CountingAction implements Triggerable
{
    public int $callCount = 0;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    #[\Override]
    public function handle(array $payload): void
    {
        $this->callCount++;
        $this->calls[] = $payload;
    }
}

