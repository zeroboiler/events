<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

test('service provider is not deferred', function (): void {
    $provider = new EventsServiceProvider($this->app);

    // A deferred provider implements DeferrableProvider and returns
    // non-empty provides(). Our provider publishes config, migrations,
    // and Artisan commands, so it MUST NOT be deferred.
    $reflection = new ReflectionClass($provider);

    expect($reflection->implementsInterface(\Illuminate\Contracts\Support\DeferrableProvider::class))->toBeFalse();
});

test('service provider publishes config tag', function (): void {
    $provider = new EventsServiceProvider($this->app);

    $publishGroups = $provider->publishes();

    expect($publishGroups)->toHaveKey('events-config');

    $configPublishes = $publishGroups['events-config'];
    expect($configPublishes)->toBeArray();
    expect(count($configPublishes))->toBeGreaterThanOrEqual(1);
});

test('service provider publishes migrations tag', function (): void {
    $provider = new EventsServiceProvider($this->app);

    $publishGroups = $provider->publishes();

    expect($publishGroups)->toHaveKey('events-migrations');

    $migrationPublishes = $publishGroups['events-migrations'];
    expect($migrationPublishes)->toBeArray();
    expect(count($migrationPublishes))->toBeGreaterThanOrEqual(1);
});
