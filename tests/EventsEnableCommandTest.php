<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Trigger::query()->delete();
});

test('enable command enables a disabled trigger', function (): void {
    $trigger = Trigger::factory()->disabled()->create();

    $this->artisan(EventsEnableCommand::class, ['id' => $trigger->id])
        ->expectsOutput("Trigger '{$trigger->name}' enabled successfully.")
        ->assertSuccessful();

    expect($trigger->fresh()->enabled)->toBeTrue();
});

test('enable command returns success when already enabled', function (): void {
    $trigger = Trigger::factory()->enabled()->create();

    $this->artisan(EventsEnableCommand::class, ['id' => $trigger->id])
        ->expectsOutput("Trigger '{$trigger->name}' is already enabled.")
        ->assertSuccessful();
});

test('enable command returns failure for non-existent trigger', function (): void {
    $this->artisan(EventsEnableCommand::class, ['id' => 'non-existent-id'])
        ->expectsOutput("Trigger 'non-existent-id' not found.")
        ->assertExitCode(Command::FAILURE);
});

test('enable command invalidates wildcard cache', function (): void {
    $trigger = Trigger::factory()->create(['enabled' => false]);

    $this->artisan(EventsEnableCommand::class, ['id' => $trigger->id])
        ->assertSuccessful();

    expect($trigger->fresh()->enabled)->toBeTrue();
});
