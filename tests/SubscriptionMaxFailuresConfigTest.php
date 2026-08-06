<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Models\Subscription;

beforeEach(function (): void {
    Subscription::query()->delete();
});

test('hasExceededFailures uses config default when no max provided', function (): void {
    Config::set('events.subscriptions.max_failures', 5);

    $sub = Subscription::factory()->create(['failure_count' => 4]);
    expect($sub->hasExceededFailures())->toBeFalse();

    $sub->increment('failure_count');
    $sub->refresh();
    expect($sub->hasExceededFailures())->toBeTrue();
});

test('hasExceededFailures uses explicit max when provided', function (): void {
    Config::set('events.subscriptions.max_failures', 5);

    $sub = Subscription::factory()->create(['failure_count' => 3]);
    expect($sub->hasExceededFailures(3))->toBeTrue();
    expect($sub->hasExceededFailures(4))->toBeFalse();
});

test('hasExceededFailures handles null config gracefully', function (): void {
    Config::set('events.subscriptions.max_failures', null);

    $sub = Subscription::factory()->create(['failure_count' => 9]);
    expect($sub->hasExceededFailures())->toBeFalse();

    $sub->increment('failure_count');
    $sub->refresh();
    expect($sub->hasExceededFailures())->toBeTrue();
});

test('hasExceededFailures handles zero failure count', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 0]);
    expect($sub->hasExceededFailures())->toBeFalse();
    expect($sub->hasExceededFailures(0))->toBeTrue();
});
