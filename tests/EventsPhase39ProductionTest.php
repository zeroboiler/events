<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Phase 39 production tests.
 *
 * Covers: README version badge consistency, test file count accuracy,
 * standalone test file verification, Pest.php completeness, composer.json version format.
 */
it('composer.json version matches README version badge', function (): void {
    $composerJson = file_get_contents(__DIR__.'/../composer.json');
    $composerData = json_decode($composerJson, true);
    expect($composerData)->toBeArray()
        ->and($composerData['version'])->toBeString()
        ->and($composerData['version'])->toMatch('/^\d+\.\d+\.\d+$/');

    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toBeString()
        ->and($readme)->toContain('version-'.$composerData['version'].'-blue');
});

it('test file count on disk is 100 (excluding infrastructure files)', function (): void {
    $testDir = __DIR__;
    $files = glob($testDir.'/*.php');
    expect($files)->not()->toBeEmpty();

    // Exclude infrastructure files
    $infrastructure = ['Pest.php', 'TestCase.php', 'CreatesApplication.php', 'helpers.php', 'TestActions.php'];
    $testFiles = array_filter($files, function (string $file) use ($infrastructure): bool {
        $basename = basename($file);

        return ! in_array($basename, $infrastructure, true);
    });

    expect(count($testFiles))->toBe(100);
});

it('Pest.php lists exactly 98 test files (2 standalone tests excluded)', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    expect($pestContent)->toBeString();

    preg_match_all('/\'([A-Za-z]+Test\.php)\'/', $pestContent, $matches);
    $listedFiles = $matches[1] ?? [];
    expect(count($listedFiles))->toBe(98);
});

it('EscapesWildcardLikeTest.php is intentionally not in Pest.php', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    expect($pestContent)->not()->toContain('EscapesWildcardLikeTest.php');

    // File exists on disk
    expect(file_exists(__DIR__.'/EscapesWildcardLikeTest.php'))->toBeTrue();
});

it('WildcardMatcherTest.php is intentionally not in Pest.php', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    expect($pestContent)->not()->toContain('WildcardMatcherTest.php');

    // File exists on disk
    expect(file_exists(__DIR__.'/WildcardMatcherTest.php'))->toBeTrue();
});

it('Pest.php comment documents standalone test files', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    expect($pestContent)->toContain('WildcardMatcherTest');
    expect($pestContent)->toContain('EscapesWildcardLikeTest');
    expect($pestContent)->toContain('plain PHP tests');
});

it('all files listed in Pest.php exist on disk', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    preg_match_all('/\'([A-Za-z]+Test\.php)\'/', $pestContent, $matches);
    $listedFiles = $matches[1] ?? [];

    foreach ($listedFiles as $file) {
        expect(file_exists(__DIR__.'/'.$file))
            ->toBeTrue("Pest.php lists {$file} but file does not exist");
    }
});

it('composer.json version is valid semver format', function (): void {
    $composerJson = file_get_contents(__DIR__.'/../composer.json');
    $composerData = json_decode($composerJson, true);
    $version = $composerData['version'] ?? '';

    expect($version)->toBeString();
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');

    $parts = explode('.', $version);
    expect(count($parts))->toBe(3);
    expect((int) $parts[0])->toBeGreaterThanOrEqual(0);
    expect((int) $parts[1])->toBeGreaterThanOrEqual(0);
    expect((int) $parts[2])->toBeGreaterThanOrEqual(0);
});

it('README contains PHP 8.5+ requirement', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain('PHP-8.5');
    expect($readme)->toContain('PHPStan-Level%209');
});

it('README contains correct test count reference', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');
    // Should say 100 test files (98 in Pest.php + 2 standalone)
    expect($readme)->toContain('100 test files');
});
