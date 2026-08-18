<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Concerns;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Shared configuration helper for reading webhook-related config values.
 *
 * Used by WebhookAction and EventsRedeliverCommand to avoid
 * duplicating config reading logic.
 *
 * Resolution order:
 * 1. If the using class has a `getConfig(): ConfigRepository` method, use it.
 * 2. Fall back to the global `app()` helper.
 */
trait GetsWebhookTimeout
{
    /**
     * Get the config repository from the container with type narrowing.
     *
     * Respects the `getConfig()` method pattern used by EventManager,
     * EventScheduler, and SubscriptionBuilder. Falls back to the
     * global `app()` helper when no `getConfig()` method exists.
     *
     * @internal Not part of the public API.
     */
    protected function getWebhookConfig(): ConfigRepository
    {
        if (method_exists($this, 'getConfig')) {
            $config = $this->getConfig();

            if ($config instanceof ConfigRepository) {
                return $config;
            }
        }

        // Fall back to the global app() helper.
        $config = app('config');

        if ($config instanceof ConfigRepository) {
            return $config;
        }

        throw new \RuntimeException('Config repository not available in the container.');
    }

    /**
     * Get the webhook timeout from config.
     *
     * Reads from `events.subscriptions.timeout` with a fallback of 30 seconds.
     */
    protected function getWebhookTimeout(): int
    {
        $timeout = $this->getWebhookConfig()->get('events.subscriptions.timeout', 30);

        return is_int($timeout) && $timeout > 0 ? $timeout : 30;
    }
}
