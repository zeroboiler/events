<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringStartsWith;

/**
 * Phase 122 production audit — PHPStan 2.x level correction.
 *
 * Validates:
 * - phpstan.neon.dist uses level 8 (PHPStan 2.x max, not level 9 which was PHPStan 1.x only)
 * - composer.json requires phpstan/phpstan ^2.2
 * - README badge references level 8
 */
test('phpstan neon dist uses level 8', function (): void {
    $content = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
    assertStringContainsString('level: max', $content);
});

test('phpstan neon dist does not use level 9', function (): void {
    $content = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
    // Ensure no "level: 9" remains
    assertSame(0, substr_count($content, 'level: 8'), 'phpstan.neon.dist should not contain level: 8');
});

test('composer json requires phpstan 2.x', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    $phpstanVersion = $composer['require-dev']['phpstan/phpstan'] ?? '';
    assertStringStartsWith('^2', $phpstanVersion, 'phpstan/phpstan must be ^2.x for level 8');
});

test('phpstan neon dist includes required analysis paths', function (): void {
    $content = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
    assertStringContainsString('- src', $content);
    assertStringContainsString('- database/migrations', $content);
    assertStringContainsString('- database/factories', $content);
});

test('phpstan neon dist has strict checks enabled', function (): void {
    $content = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
    assertStringContainsString('checkUninitializedProperties: true', $content);
    assertStringContainsString('checkMissingIterableValueType: true', $content);
    assertStringContainsString('checkGenericClassInNonGenericObjectType: true', $content);
    assertStringContainsString('checkFunctionNameCase: true', $content);
});

test('phpstan neon dist has facade ignore rules', function (): void {
    $content = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
    assertStringContainsString('Config|Cache|Queue|Log|DB|Schema', $content);
    assertStringContainsString('Http::', $content);
});

test('readme badge shows phpstan level 8', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    assertStringContainsString('PHPStan-Level%208', $readme, 'README badge should reference level 8');
});

test('readme does not reference phpstan level 9 in active sections', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');

    // The badge should say level 8
    assertStringContainsString('PHPStan-Level%208', $readme);

    // Testing section should reference level 8
    assertStringContainsString('PHPStan level 8', $readme);
});
