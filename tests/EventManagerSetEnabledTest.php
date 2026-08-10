<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes (App\Actions namespace)
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Config::set('events.disabled', false);
});

test('setEnabled(false) disables the event system at runtime', function (): void {
    $manager = app(EventManager::class);

    expect($manager->isDisabled())->toBeFalse();

    $manager->setEnabled(false);

    expect($manager->isDisabled())->toBeTrue();
});

test('setEnabled(true) enables the event system at runtime', function (): void {
    $manager = app(EventManager::class);

    // Start from disabled state
    Config::set('events.disabled', true);
    expect($manager->isDisabled())->toBeTrue();

    $manager->setEnabled(true);

    expect($manager->isDisabled())->toBeFalse();
});

test('setEnabled(false) prevents fire() from dispatching triggers', function (): void {
    $manager = app(EventManager::class);

    // Register a trigger
    $manager->on('test.event')
        ->action(\App\Actions\SendOrderNotification::class)
        ->save();

    // Verify it would normally fire
    $manager->fire('test.event', ['key' => 'value']);
    expect(EventLog::count())->toBe(1);

    // Clear logs
    EventLog::query()->delete();

    // Disable and fire again
    $manager->setEnabled(false);
    $manager->fire('test.event', ['key' => 'value']);

    expect(EventLog::count())->toBe(0);
});

test('setEnabled(true) re-enables fire() dispatching after disable', function (): void {
    $manager = app(EventManager::class);

    $manager->on('test.event')
        ->action(\App\Actions\SendOrderNotification::class)
        ->save();

    // Disable
    $manager->setEnabled(false);
    $manager->fire('test.event', ['key' => 'value']);
    expect(EventLog::count())->toBe(0);

    // Re-enable
    $manager->setEnabled(true);
    $manager->fire('test.event', ['key' => 'value']);
    expect(EventLog::count())->toBe(1);
});

test('isDisabled() returns true when config is set to true', function (): void {
    $manager = app(EventManager::class);

    Config::set('events.disabled', true);

    expect($manager->isDisabled())->toBeTrue();
});

test('isDisabled() returns false when config is set to false', function (): void {
    $manager = app(EventManager::class);

    Config::set('events.disabled', false);

    expect($manager->isDisabled())->toBeFalse();
});

test('isDisabled() returns false when config key is missing', function (): void {
    $manager = app(EventManager::class);

    Config::set('events.disabled', null);

    expect($manager->isDisabled())->toBeFalse();
});
