<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Shared configuration helper for reading webhook-related config values.
 *
 * Used by WebhookAction and EventsRedeliverCommand to avoid
 * duplicating config reading logic.
 *
 * Requires the using class to implement `getConfig(): ConfigRepository`
 * or have a `$app` property with container access.
 */
trait GetsWebhookTimeout
{
    /**
     * Get the config repository from the container with type narrowing.
     *
     * Respects the `getConfig()` method pattern used by EventManager,
     * EventScheduler, and SubscriptionBuilder. Falls back to container
     * resolution via `$this->app` when used in trait contexts.
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

        // Fallback for trait contexts (e.g., EventsRedeliverCommand uses GetsWebhookTimeout
        // but doesn't have its own getConfig() — it reads from container via app()).
        // Note: property_exists() checks for the property existence regardless of visibility.
        if (property_exists($this, 'app')) {
            /** @var Container|null $app */
            $app = $this->app ?? null;
            if ($app instanceof Container) {
                $config = $app->get('config');
                if ($config instanceof ConfigRepository) {
                    return $config;
                }
            }
        }

        // Final fallback: global app() helper (for commands without container injection)
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
