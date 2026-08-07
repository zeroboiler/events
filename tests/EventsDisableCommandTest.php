<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Command;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Trigger::query()->delete();
});

test('disable command disables an enabled trigger', function (): void {
    $trigger = Trigger::factory()->enabled()->create();

    $this->artisan(EventsDisableCommand::class, ['id' => $trigger->id])
        ->expectsOutput("Trigger '{$trigger->name}' disabled successfully.")
        ->assertSuccessful();

    expect($trigger->fresh()->enabled)->toBeFalse();
});

test('disable command returns success when already disabled', function (): void {
    $trigger = Trigger::factory()->disabled()->create();

    $this->artisan(EventsDisableCommand::class, ['id' => $trigger->id])
        ->expectsOutput("Trigger '{$trigger->name}' is already disabled.")
        ->assertSuccessful();
});

test('disable command returns failure for non-existent trigger', function (): void {
    $this->artisan(EventsDisableCommand::class, ['id' => 'non-existent-id'])
        ->expectsOutput("Trigger 'non-existent-id' not found.")
        ->assertExitCode(Command::FAILURE);
});

test('disable command invalidates wildcard cache', function (): void {
    $trigger = Trigger::factory()->create(['enabled' => true]);

    $this->artisan(EventsDisableCommand::class, ['id' => $trigger->id])
        ->assertSuccessful();

    expect($trigger->fresh()->enabled)->toBeFalse();
});
