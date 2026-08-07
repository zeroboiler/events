<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Command;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestActions\SimpleAction;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
});

test('retry command returns failure for invalid status', function (): void {
    $this->artisan(EventsRetryCommand::class, ['--status' => 'invalid'])
        ->expectsOutput('Invalid status. Must be "failed" or "pending".')
        ->assertExitCode(Command::FAILURE);
});

test('retry command shows message when no failed logs exist', function (): void {
    $this->artisan(EventsRetryCommand::class, ['--status' => 'failed'])
        ->expectsOutput('No failed logs found.')
        ->assertSuccessful();
});

test('retry command shows message when no pending logs exist', function (): void {
    $this->artisan(EventsRetryCommand::class, ['--status' => 'pending'])
        ->expectsOutput('No pending logs found.')
        ->assertSuccessful();
});

test('retry command skips disabled triggers', function (): void {
    $trigger = Trigger::factory()->disabled()->create();
    EventLog::factory()
        ->failed()
        ->for($trigger)
        ->create(['event' => 'test.retry']);

    $this->artisan(EventsRetryCommand::class, [
        '--status' => 'failed',
    ])
        ->expectsOutput('Found 1 failed log(s).')
        ->expectsOutput('Skipping log')
        ->expectsOutput('trigger not found or disabled')
        ->assertSuccessful();
});

test('retry command handles trigger without log gracefully', function (): void {
    EventLog::factory()
        ->failed()
        ->create([
            'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'test.orphaned',
        ]);

    $this->artisan(EventsRetryCommand::class, [
        '--status' => 'failed',
    ])
        ->expectsOutput('Skipping log')
        ->expectsOutput('trigger not found or disabled')
        ->assertSuccessful();
});

test('retry command defaults to failed status', function (): void {
    $this->artisan(EventsRetryCommand::class)
        ->expectsOutput('No failed logs found.')
        ->assertSuccessful();
});
