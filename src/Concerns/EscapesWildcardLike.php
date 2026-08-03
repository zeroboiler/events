<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Concerns;

/**
 * Normalises event-name wildcards (* → %) for SQL LIKE queries.
 *
 * The same escaping logic was previously duplicated in EventManager
 * (listSubscriptions, getEventHistory) and Subscription::scopeForEvent.
 */
trait EscapesWildcardLike
{
    /**
     * Convert an event pattern with * wildcards into a SQL LIKE pattern.
     *
     * Backslashes, percent signs and underscores are escaped so they
     * are treated literally; asterisks are converted to % wildcards.
     *
     * Returns null when the pattern does not contain any wildcard.
     */
    protected function wildcardToLike(string $pattern): ?string
    {
        if (! str_contains($pattern, '*')) {
            return null;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pattern);

        return str_replace('*', '%', $escaped);
    }
}
