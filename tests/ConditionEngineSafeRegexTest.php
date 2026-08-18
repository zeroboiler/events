<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;

describe('ConditionEngine::safeRegexMatch — security', function (): void {
    it('rejects patterns exceeding max length (500 chars)', function (): void {
        $engine = new ConditionEngine;

        // Access protected method via reflection (PHP 8.5: setAccessible no longer needed)
        $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');

        $longPattern = '/^' . str_repeat('a', 501) . '$/';
        $result = $ref->invoke($engine, $longPattern, 'test');

        expect($result)->toBeFalse();
    });

    it('accepts patterns within max length', function (): void {
        $engine = new ConditionEngine;

        $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');

        $pattern = '/^[a-z]+$/';
        $result = $ref->invoke($engine, $pattern, 'hello');

        expect($result)->toBeTrue();
    });

    it('rejects nested quantifier patterns (ReDoS prevention)', function (): void {
        $engine = new ConditionEngine;

        $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');

        // These patterns contain nested quantifiers that can cause catastrophic backtracking
        $dangerousPatterns = [
            '/(a+)+$/',
            '/(a*)*$/',
            '/(a+?){/',
            '/([a-z]+)*$/',
        ];

        foreach ($dangerousPatterns as $pattern) {
            $result = $ref->invoke($engine, $pattern, 'aaaa');
            expect($result)->toBeFalse("Pattern {$pattern} should be rejected");
        }
    });

    it('allows safe regex patterns', function (): void {
        $engine = new ConditionEngine;

        $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');

        $safePatterns = [
            ['pattern' => '/^[A-Z]{3}-\d{4}$/', 'subject' => 'ABC-1234', 'expected' => true],
            ['pattern' => '/^\d+$/', 'subject' => '42', 'expected' => true],
            ['pattern' => '/^test/', 'subject' => 'testing', 'expected' => true],
            ['pattern' => '/^\d+$/', 'subject' => 'abc', 'expected' => false],
        ];

        foreach ($safePatterns as ['pattern' => $pattern, 'subject' => $subject, 'expected' => $expected]) {
            $result = $ref->invoke($engine, $pattern, $subject);
            expect($result)->toBe($expected, "Pattern {$pattern} against '{$subject}' should return {$expected}");
        }
    });

    it('returns false for invalid regex patterns', function (): void {
        $engine = new ConditionEngine;

        $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');

        $result = $ref->invoke($engine, '/[invalid/', 'test');

        expect($result)->toBeFalse();
    });

    it('restores pcre.backtrack_limit after evaluation', function (): void {
        $engine = new ConditionEngine;

        $ref = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');

        $originalLimit = (string) ini_get('pcre.backtrack_limit');

        $ref->invoke($engine, '/^test$/', 'test');

        $afterLimit = (string) ini_get('pcre.backtrack_limit');

        expect($afterLimit)->toBe($originalLimit);
    });

    it('handles matches operator in condition evaluation with safe pattern', function (): void {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['code' => ['matches', '/^[A-Z]{2}-\d{3}$/']],
            ['code' => 'AB-123'],
        );

        expect($result)->toBeTrue();
    });

    it('handles matches operator in condition evaluation with dangerous pattern', function (): void {
        $engine = new ConditionEngine;

        // Dangerous pattern should be rejected by safeRegexMatch
        $result = $engine->matches(
            ['input' => ['matches', '/(a+)+$/']],
            ['input' => 'aaaa'],
        );

        expect($result)->toBeFalse();
    });

    it('handles matches operator with non-string actual value', function (): void {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['count' => ['matches', '/^\d+$/']],
            ['count' => 42],
        );

        // Non-string actual should fail the is_string() check in the match arm
        expect($result)->toBeFalse();
    });

    it('handles matches operator with non-string expected value', function (): void {
        $engine = new ConditionEngine;

        $result = $engine->matches(
            ['name' => ['matches', 123]],
            ['name' => 'test'],
        );

        // Non-string expected should fail the is_string() check
        expect($result)->toBeFalse();
    });
});
