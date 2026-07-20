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
     * - Wildcard-only pattern (*): matches ANY event name (catch-all),
     *   including multi-segment dotted events like order.placed.extra
     * - Double wildcard (**): same as single * — matches across segment boundaries.
     *   Kept for backwards compatibility and explicit readability.
     * - Single wildcard segment in pattern (*): matches one OR MORE dot-delimited
     *   segments. e.g. order.* matches order.placed, order.shipped, AND
     *   order.placed.extra (issue #8 — docs say * matches multi-segment).
     * - Multiple wildcards: user.*.created matches user.profile.created
     *
     * @param  string  $pattern  The pattern with * wildcards (e.g., "order.*")
     * @param  string  $event  The event to match (e.g., "order.placed")
     */
    public static function matches(string $pattern, string $event): bool
    {
        // Handle catch-all patterns — match everything (except empty string)
        if ($pattern === '*' || $pattern === '**') {
            return $event !== '';
        }

        // Escape regex special chars (except our * wildcards).
        $regex = preg_quote($pattern, '/');

        // Convert ** (multi-segment wildcard) to .* (matches across dots)
        // MUST be done before single * conversion to avoid double-processing.
        $regex = str_replace('\*\*', '.*', $regex);
        // Convert remaining * to .* (matches zero+ chars including dots, issue #8)
        $regex = str_replace('\\*', '.*', $regex);

        $regex = '/^'.$regex.'$/';

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
        return array_filter($patterns, fn (string $pattern): bool => self::matches($pattern, $event));
    }

    /**
     * Extract the wildcard parts from a matched event.
     *
     * For example, with pattern "user.*.created" and event "user.profile.created",
     * returns ["profile"].
     *
     * With pattern "order.*" and event "order.placed.extra", the * captures
     * "placed.extra" (multi-segment, issue #8).
     *
     * @return array<int, string>
     */
    public static function extractWildcards(string $pattern, string $event): array
    {
        // Handle bare * catch-all: return the entire event as the wildcard value
        if ($pattern === '*') {
            return $event !== '' ? [$event] : [];
        }

        // First verify that the event actually matches the pattern
        if (! self::matches($pattern, $event)) {
            return [];
        }

        // Build a regex that captures wildcard values, supporting multi-segment *
        $regex = preg_quote($pattern, '/');
        // ** matches across segment boundaries
        $regex = str_replace('\*\*', '(.+)', $regex);
        // Single * also matches one or more segments (issue #8)
        $regex = str_replace('\*', '(.+)', $regex);
        $regex = '/^'.$regex.'$/';

        if (preg_match($regex, $event, $matches) === 1) {
            // $matches[0] is the full match; captures start at index 1
            return array_slice($matches, 1);
        }

        return [];
    }
}
