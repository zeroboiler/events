<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Validates WildcardMatcher class architecture constraints:
 * - readonly final class
 * - all public methods are #[Pure] static
 * - no mutable state
 */
final class WildcardMatcherArchitectureTest extends TestCase
{
    public function test_wildcard_matcher_is_readonly_final_class(): void
    {
        $ref = new ReflectionClass(WildcardMatcher::class);

        self::assertTrue(
            $ref->isReadOnly(),
            'WildcardMatcher must be a readonly class (PHP 8.5+).',
        );

        self::assertTrue(
            $ref->isFinal(),
            'WildcardMatcher must be a final class.',
        );
    }

    public function test_wildcard_matcher_all_public_methods_are_static_and_pure(): void
    {
        $ref = new ReflectionClass(WildcardMatcher::class);
        $publicMethods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);

        self::assertNotEmpty($publicMethods, 'WildcardMatcher must have public methods.');

        foreach ($publicMethods as $method) {
            self::assertTrue(
                $method->isStatic(),
                "WildcardMatcher::{$method->getName()}() must be static.",
            );

            $pureAttributes = $method->getAttributes('Pure');
            self::assertNotEmpty(
                $pureAttributes,
                "WildcardMatcher::{$method->getName()}() must have #[Pure] attribute.",
            );
        }
    }

    public function test_wildcard_matcher_has_no_properties(): void
    {
        $ref = new ReflectionClass(WildcardMatcher::class);
        $properties = $ref->getProperties();

        self::assertEmpty(
            $properties,
            'WildcardMatcher should have no properties (stateless utility class).',
        );
    }

    public function test_wildcard_matcher_returns_list_from_findMatchingPatterns(): void
    {
        $result = WildcardMatcher::findMatchingPatterns(['order.*', 'user.*'], 'order.placed');

        self::assertIsArray($result);
        self::assertContains('order.*', $result);
        self::assertNotContains('user.*', $result);
    }

    public function test_wildcard_matcher_returns_list_from_extractWildcards(): void
    {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        self::assertIsArray($result);
        self::assertSame(['profile'], $result);
    }

    public function test_wildcard_matcher_matches_is_pure_deterministic(): void
    {
        // Call twice with same input — must return same result (pure)
        $r1 = WildcardMatcher::matches('order.*', 'order.placed');
        $r2 = WildcardMatcher::matches('order.*', 'order.placed');

        self::assertSame($r1, $r2);
    }
}
