<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('phpstan.neon.dist exists and contains level 9', function (): void {
    $path = __DIR__.'/../phpstan.neon.dist';

    expect(File::exists($path))->toBeTrue('phpstan.neon.dist must exist for PHPStan analysis');

    $content = File::get($path);

    expect($content)->toContain('level: 9', 'PHPStan level must be 9')
        ->and($content)->toContain('paths:', 'PHPStan must define paths')
        ->and($content)->toContain('src', 'PHPStan must scan src/ directory');
});

test('phpstan.neon.dist reportUnusedIgnoredErrors is true', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($content)->toContain('reportUnusedIgnoredErrors: true');
});
