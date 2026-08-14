<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('PHPStan Level 9 Configuration', function (): void {
    test('phpstan.neon.dist exists and sets level to 9', function (): void {
        $path = realpath(__DIR__.'/../phpstan.neon.dist');

        expect($path)->toBeString();
        expect(file_exists($path))->toBeTrue();

        $content = file_get_contents($path);

        expect($content)->toBeString();
        expect($content)->toContain('level: 9');
        expect($content)->not->toContain('level: 9');
        expect($content)->not->Contain('level: 9');
    });

    test('phpstan.neon.dist scans src directory', function (): void {
        $path = realpath(__DIR__.'/../phpstan.neon.dist');
        $content = file_get_contents($path);

        expect($content)->toContain("paths:\n        - src");
    });

    test('phpstan.neon.dist has treatPhpDocTypesAsCertain false', function (): void {
        $path = realpath(__DIR__.'/../phpstan.neon.dist');
        $content = file_get_contents($path);

        expect($content)->toContain('treatPhpDocTypesAsCertain: false');
    });

    test('phpstan.neon.dist reports unmatched ignored errors', function (): void {
        $path = realpath(__DIR__.'/../phpstan.neon.dist');
        $content = file_get_contents($path);

        expect($content)->toContain('reportUnmatchedIgnoredErrors: true');
    });

    test('phpstan.neon.dist has checkUninitializedProperties false for Eloquent', function (): void {
        $path = realpath(__DIR__.'/../phpstan.neon.dist');
        $content = file_get_contents($path);

        expect($content)->toContain('checkUninitializedProperties: false');
    });

    test('CI workflow references phpstan.neon.dist', function (): void {
        $path = realpath(__DIR__.'/../.github/workflows/ci.yml');
        $content = file_get_contents($path);

        expect($content)->toBeString();
        expect($content)->toContain('--configuration=phpstan.neon.dist');
    });

    test('CI workflow uses PHP 8.5', function (): void {
        $path = realpath(__DIR__.'/../.github/workflows/ci.yml');
        $content = file_get_contents($path);

        expect($content)->toContain("php-version: '8.5'");
    });

    test('composer.json requires php ^8.5', function (): void {
        $content = file_get_contents(realpath(__DIR__.'/../composer.json'));
        $composer = json_decode($content, true);

        expect($composer)->toBeArray();
        expect($composer['require']['php'])->toBe('^8.5');
    });

    test('composer.json requires phpstan ^2.2', function (): void {
        $content = file_get_contents(realpath(__DIR__.'/../composer.json'));
        $composer = json_decode($content, true);

        expect($composer)->toBeArray();
        expect($composer['require-dev']['phpstan/phpstan'])->toBe('^2.2');
    });
});
