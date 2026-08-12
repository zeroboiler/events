<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Concerns;

use Illuminate\Support\Facades\Config;

/**
 * Shared configuration helper for reading webhook-related config values.
 *
 * Used by WebhookAction and EventsRedeliverCommand to avoid
 * duplicating config reading logic.
 */
trait GetsWebhookTimeout
{
    /**
     * Get the webhook timeout from config.
     *
     * Reads from `events.subscriptions.timeout` with a fallback of 30 seconds.
     */
    protected function getWebhookTimeout(): int
    {
        $timeout = Config::get('events.subscriptions.timeout', 30);

        return is_int($timeout) && $timeout > 0 ? $timeout : 30;
    }
}
