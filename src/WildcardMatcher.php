<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

final class WildcardMatcher
{
    /**
     * Match a pattern with wildcards against an event string.
     *
     * Supports:
     * - Wildcard-only pattern (*): matches ANY event name (catch-all),
     *   including multi-segment dotted events like order.placed.extra
     * - Double wildcard (**): matches across segment boundaries
     *   e.g. order.** matches order.placed, order.placed.extra, etc.
     * - Single wildcard segment in pattern: matches one dot-delimited segment
     *   e.g. order.* matches order.placed, order.shipped but NOT order.placed.extra
     * - Multiple wildcards: user.*.created matches user.profile.created
     *
     * @param  string  $pattern  The pattern with * wildcards (e.g., "order.*")
     * @param  string  $event  The event to match (e.g., "order.placed")
     */
    #[\Pure]
    public static function matches(string $pattern, string $event): bool
    {
        // Handle catch-all patterns — match everything (except empty string)
        if ($pattern === '*') {
            return $event !== '';
        }

        if ($pattern === '**') {
            return $event !== '';
        }

        // Escape regex special chars (except our * wildcards).
        $regex = preg_quote($pattern, '/');

        // Convert ** (multi-segment wildcard) to .* (matches across dots)
        // MUST be done before single * conversion to avoid double-processing.
        $regex = str_replace('\*\*', '.*', $regex);

        // Convert remaining * (single-segment wildcard) to [^.]* (matches within a segment)
        $regex = str_replace('\*', '[^.]*', $regex);

        $regex = '/^'.$regex.'$/';

        return (bool) preg_match($regex, $event);
    }

    /**
     * Find all patterns that match an event.
     *
     * @param  array<int, string>  $patterns
     * @return list<string> Patterns from the input that match the event
     */
    #[\Pure]
    public static function findMatchingPatterns(array $patterns, string $event): array
    {
        return array_values(array_filter(
            $patterns,
            fn (string $pattern): bool => self::matches($pattern, $event),
        ));
    }

    /**
     * Extract the wildcard parts from a matched event.
     *
     * For example, with pattern "user.*.created" and event "user.profile.created",
     * returns ["profile"].
     *
     * Note: ** (cross-segment) wildcards are NOT extracted as they match
     * variable-length segments. Only single-segment (*) wildcards are
     * extracted. If the pattern contains ** and segments don't align,
     * an empty array is returned.
     *
     * @return list<string> Extracted wildcard values from the event
     */
    public static function extractWildcards(string $pattern, string $event): array
    {
        // Cross-segment wildcards don't have a fixed number of parts — can't extract reliably
        if (str_contains($pattern, '**')) {
            return [];
        }

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
