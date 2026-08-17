<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Str;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $trigger = Trigger::factory()->enabled()->create([
        'event' => 'order.placed',
        'action' => \ZeroBoiler\Events\Tests\Actions\LogAction',
    ]);

    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.placed',
        'status' => EventLog::STATUS_COMPLETED,
    ]);

    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'user.registered',
        'status' => EventLog::STATUS_COMPLETED,
    ]);

    EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.shipped',
        'status' => EventLog::STATUS_FAILED,
    ]);
});

it('filters logs by exact event name', function (): void {
    $this->artisan('zeroboiler:events:log', ['--event' => 'order.placed'])
        ->expectsTable(
            ['ID', 'Event', 'Trigger', 'Status', 'Duration', 'Created At'],
            fn (array $rows): bool => count($rows) === 1
                && $rows[0][1] === 'order.placed',
        )
        ->assertSuccessful();
});

it('filters logs by wildcard event pattern', function (): void {
    $this->artisan('zeroboiler:events:log', ['--event' => 'order.*'])
        ->expectsTable(
            ['ID', 'Event', 'Trigger', 'Status', 'Duration', 'Created At'],
            fn (array $rows): bool => count($rows) === 2,
        )
        ->assertSuccessful();
});

it('shows all logs when no event filter is provided', function (): void {
    $this->artisan('zeroboiler:events:log')
        ->expectsTable(
            ['ID', 'Event', 'Trigger', 'Status', 'Duration', 'Created At'],
            fn (array $rows): bool => count($rows) === 3,
        )
        ->assertSuccessful();
});

it('returns empty when no logs match the event filter', function (): void {
    $this->artisan('zeroboiler:events:log', ['--event' => 'payment.processed'])
        ->expectsOutput('No event logs found.')
        ->assertSuccessful();
});

it('combines event filter with status filter', function (): void {
    $this->artisan('zeroboiler:events:log', ['--event' => 'order.*', '--status' => 'completed'])
        ->expectsTable(
            ['ID', 'Event', 'Trigger', 'Status', 'Duration', 'Created At'],
            fn (array $rows): bool => count($rows) === 1
                && $rows[0][1] === 'order.placed',
        )
        ->assertSuccessful();
});

it('combines event filter with trigger filter', function (): void {
    $trigger = Trigger::first();
    $this->artisan('zeroboiler:events:log', ['--event' => 'order.*', '--trigger' => $trigger->id])
        ->expectsTable(
            ['ID', 'Event', 'Trigger', 'Status', 'Duration', 'Created At'],
            fn (array $rows): bool => count($rows) === 2,
        )
        ->assertSuccessful();
});

it('respects limit option with event filter', function (): void {
    $this->artisan('zeroboiler:events:log', ['--event' => '*', '--limit' => 2])
        ->expectsTable(
            ['ID', 'Event', 'Trigger', 'Status', 'Duration', 'Created At'],
            fn (array $rows): bool => count($rows) === 2,
        )
        ->assertSuccessful();
});
