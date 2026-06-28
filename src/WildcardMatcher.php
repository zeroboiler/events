<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

class WildcardMatcher
{
    /**
     * Match a pattern with wildcards against an event string.
     *
     * Supports:
     * - Single wildcard (*): matches one or more characters including dots
     *   e.g. order.* matches order.placed, order.shipped, order.created.premium
     * - Multiple wildcards: user.*.created matches user.profile.created
     * - Wildcard only: * matches anything including dotted events
     *
     * Bug #406: Previously `*` was converted to `[^.]*` which only matched
     * a single dot-delimited segment. Now `*` is converted to `.*` so it
     * matches across multiple segments.
     *
     * @param  string  $pattern  The pattern with * wildcards (e.g., "order.*")
     * @param  string  $event  The event to match (e.g., "order.placed")
     */
    public static function matches(string $pattern, string $event): bool
    {
        // Handle wildcard-only pattern specially — matches everything
        if ($pattern === '*') {
            return ! empty($event);
        }

        // Escape regex special chars (except our * wildcards), then convert
        // * to .* so it matches one or more characters including dots.
        $regex = str_replace('\*', '.*', preg_quote($pattern, '/'));

        $regex = '/^' . $regex . '$/';

        return (bool) preg_match($regex, $event);
    }

    /**
     * Find all patterns that match an event.
     *
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    public static function findMatchingPatterns(array $patterns, string $event): array
    {
        return array_filter($patterns, fn (string $pattern) => self::matches($pattern, $event));
    }

    /**
     * Extract the wildcard parts from a matched event.
     *
     * For example, with pattern "user.*.created" and event "user.profile.created",
     * returns ["profile"].
     *
     * @return array<int, string>
     */
    public static function extractWildcards(string $pattern, string $event): array
    {
        $parts = explode('.', $pattern);
        $eventParts = explode('.', $event);

        if (count($parts) !== count($eventParts)) {
            return [];
        }

        // First verify that the event actually matches the pattern
        if (! self::matches($pattern, $event)) {
            return [];
        }

        $wildcards = [];
        foreach ($parts as $i => $part) {
            if ($part === '*') {
                $wildcards[] = $eventParts[$i] ?? '';
            }
        }

        return $wildcards;
    }
}
