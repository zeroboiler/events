<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Command;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestActions\SimpleAction;

beforeEach(function (): void {
    Trigger::query()->delete();
});

test('register command creates a sync trigger', function (): void {
    $this->artisan(EventsRegisterCommand::class, [
        'event' => 'test.registered',
        'action' => SimpleAction::class,
    ])
        ->expectsOutput('created successfully!')
        ->assertSuccessful();

    $trigger = Trigger::where('event', 'test.registered')->first();
    expect($trigger)->not->toBeNull()
        ->and($trigger->async)->toBeFalse()
        ->and($trigger->priority)->toBe(0)
        ->and($trigger->enabled)->toBeTrue();
});

test('register command creates an async trigger', function (): void {
    $this->artisan(EventsRegisterCommand::class, [
        'event' => 'test.async',
        'action' => SimpleAction::class,
        '--async' => true,
    ])
        ->expectsOutput('created successfully!')
        ->assertSuccessful();

    $trigger = Trigger::where('event', 'test.async')->first();
    expect($trigger)->not->toBeNull()
        ->and($trigger->async)->toBeTrue();
});

test('register command sets name via option', function (): void {
    $this->artisan(EventsRegisterCommand::class, [
        'event' => 'test.named',
        'action' => SimpleAction::class,
        '--name' => 'My Custom Trigger',
    ])
        ->expectsOutput("Trigger 'My Custom Trigger' created successfully!")
        ->assertSuccessful();
});

test('register command sets priority via option', function (): void {
    $this->artisan(EventsRegisterCommand::class, [
        'event' => 'test.priority',
        'action' => SimpleAction::class,
        '--priority' => 50,
    ])
        ->assertSuccessful();

    $trigger = Trigger::where('event', 'test.priority')->first();
    expect($trigger)->not->toBeNull()
        ->and($trigger->priority)->toBe(50);
});

test('register command generates name from event when not provided', function (): void {
    $this->artisan(EventsRegisterCommand::class, [
        'event' => 'order.placed',
        'action' => SimpleAction::class,
    ])
        ->expectsOutput("Trigger 'order.placed Trigger' created successfully!")
        ->assertSuccessful();
});

test('register command handles missing action gracefully', function (): void {
    $this->artisan(EventsRegisterCommand::class, [
        'event' => 'test.empty',
        'action' => '',
    ])
        ->expectsOutput('Failed to create trigger')
        ->assertExitCode(Command::FAILURE);
});
