<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 *
 * Test coverage for EventsCleanupCommand.
 * Covers retention policy cleanup of old EventLog records.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use ZeroBoiler\Events\Console\EventsCleanupCommand;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes (App\Actions namespace)
require_once __DIR__.'/TestActions.php';

/**
 * Run a command with given arguments/options and return [exitCode, output].
 *
 * @param  class-string<Command>  $commandClass
 * @param  array<string, mixed>  $arguments
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runCleanupCommand(string $commandClass, array $arguments = [], array $options = [], bool $interactive = true): array
{
    $command = new $commandClass;

    /** @var Container $app */
    $app = app();
    $command->setLaravel($app);

    $definition = $command->getDefinition();

    /** @var array<string, string|bool|array<int, string>> $inputData */
    $inputData = [];
    foreach ($arguments as $name => $value) {
        $inputData[$name] = $value;
    }
    foreach ($options as $name => $value) {
        $key = '--'.$name;
        if (is_array($value)) {
            $inputData[$key] = $value;
        } elseif (is_bool($value) && $value) {
            $inputData[$key] = true;
        } elseif (is_string($value)) {
            $inputData[$key] = $value;
        }
    }

    $input = new ArrayInput($inputData, $definition);
    $input->setInteractive($interactive);

    $output = new BufferedOutput;

    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    // Reset config to default
    Config::set('events.retention_days', 30);
});

test('cleanup command has correct signature', function (): void {
    $command = new EventsCleanupCommand;

    expect($command->getName())->toBe('zeroboiler:events:cleanup')
        ->and($command->getDescription())->toBe('Clean up old event logs based on retention policy');
});

test('cleanup command reports no logs when database is empty', function (): void {
    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'days' => '30',
        'force' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('No event logs to clean up.');
});

test('cleanup command soft-deletes old logs', function (): void {
    /** @var Trigger $trigger */
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Old log (35 days ago)
    EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'old.event',
        'created_at' => Carbon::now()->subDays(35),
        'updated_at' => Carbon::now()->subDays(35),
    ]);

    // Recent log (5 days ago)
    EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'recent.event',
        'created_at' => Carbon::now()->subDays(5),
        'updated_at' => Carbon::now()->subDays(5),
    ]);

    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'days' => '30',
        'force' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Soft-deleted 1 log(s).');

    // Old log should be soft-deleted (not in default query results)
    expect(EventLog::count())->toBe(1)
        ->and(EventLog::withTrashed()->count())->toBe(2)
        ->and(EventLog::onlyTrashed()->count())->toBe(1);

    /** @var EventLog $trashedLog */
    $trashedLog = EventLog::onlyTrashed()->first();
    expect($trashedLog->event)->toBe('old.event');
});

test('cleanup command uses retention_days from config when days option not provided', function (): void {
    Config::set('events.retention_days', 10);

    /** @var Trigger $trigger */
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // 12-day-old log — should be deleted with 10-day retention
    EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'old.event',
        'created_at' => Carbon::now()->subDays(12),
        'updated_at' => Carbon::now()->subDays(12),
    ]);

    // 5-day-old log — should be retained
    EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'recent.event',
        'created_at' => Carbon::now()->subDays(5),
        'updated_at' => Carbon::now()->subDays(5),
    ]);

    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'force' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('older than 10 day(s)')
        ->and($output)->toContain('Soft-deleted 1 log(s).');

    expect(EventLog::count())->toBe(1);

    /** @var EventLog $remaining */
    $remaining = EventLog::first();
    expect($remaining->event)->toBe('recent.event');
});

test('cleanup command filters by status', function (): void {
    /** @var Trigger $trigger */
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Old failed log
    EventLog::factory()->failed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'old.failed',
        'created_at' => Carbon::now()->subDays(40),
        'updated_at' => Carbon::now()->subDays(40),
    ]);

    // Old completed log
    EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'old.completed',
        'created_at' => Carbon::now()->subDays(40),
        'updated_at' => Carbon::now()->subDays(40),
    ]);

    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'days' => '30',
        'status' => 'failed',
        'force' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain("status 'failed'")
        ->and($output)->toContain('Soft-deleted 1 log(s).');

    // Only the failed log should be deleted; completed log remains
    expect(EventLog::count())->toBe(1);

    /** @var EventLog $remaining */
    $remaining = EventLog::first();
    expect($remaining->event)->toBe('old.completed');
});

test('cleanup command skips when retention days is zero', function (): void {
    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'days' => '0',
        'force' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Retention days is 0 or less');
});

test('cleanup command can be cancelled via confirmation', function (): void {
    /** @var Trigger $trigger */
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'old.event',
        'created_at' => Carbon::now()->subDays(40),
        'updated_at' => Carbon::now()->subDays(40),
    ]);

    // Non-interactive with no --force: confirm() returns false
    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'days' => '30',
    ], interactive: false);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Cleanup cancelled.');

    // Log should NOT be deleted
    expect(EventLog::count())->toBe(1);
});

test('cleanup command handles large datasets with chunking', function (): void {
    /** @var Trigger $trigger */
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Create 250 old logs (exceeds chunk size of 200)
    for ($i = 0; $i < 250; $i++) {
        EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'event' => "bulk.event.{$i}",
            'created_at' => Carbon::now()->subDays(40),
            'updated_at' => Carbon::now()->subDays(40),
        ]);
    }

    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'days' => '30',
        'force' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Soft-deleted 250 log(s).');

    expect(EventLog::count())->toBe(0)
        ->and(EventLog::onlyTrashed()->count())->toBe(250);
});

test('cleanup command warns on invalid status', function (): void {
    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'days' => '30',
        'status' => 'invalid',
        'force' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain("Unknown status 'invalid'");
});

test('cleanup command does not affect recent logs', function (): void {
    /** @var Trigger $trigger */
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    // Log from today
    EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'today.event',
    ]);

    // Log from yesterday
    EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'yesterday.event',
        'created_at' => Carbon::now()->subDay(),
        'updated_at' => Carbon::now()->subDay(),
    ]);

    [$exit, $output] = runCleanupCommand(EventsCleanupCommand::class, [], [
        'days' => '30',
        'force' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('No event logs to clean up.');

    expect(EventLog::count())->toBe(2);
});
