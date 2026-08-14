<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

test('database_path helper returns correct path with non-empty argument', function (): void {
    $result = database_path('migrations');
    expect($result)->toBe('/database/migrations');
});

test('database_path helper returns correct path with empty argument', function (): void {
    $result = database_path('');
    expect($result)->toBe('/database');
});

test('database_path helper does not produce double slashes', function (): void {
    $result = database_path('migrations/2024_01_01_000001_create_triggers_table.php');
    expect($result)->toBe('/database/migrations/2024_01_01_000001_create_triggers_table.php');
    expect($result)->not->toContain('//');
});
