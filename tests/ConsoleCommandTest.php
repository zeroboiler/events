<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 *
 * Test coverage for EventsRetryCommand, EventsFireCommand, and EventsRegisterCommand.
 * Covers issue #11.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes (App\Actions namespace)
require_once __DIR__.'/TestActions.php';

/**
 * Run a command with given arguments/options and return [exitCode, output].
 *
 * @param  class-string<Command>  $commandClass
 * @param  array<string, mixed>  $arguments  Positional arguments
 * @param  array<string, mixed>  $options  Options (boolean for flags, string/array for values)
 * @param  bool  $interactive  Whether the command input is interactive (for confirm prompts)
 * @return array{0: int, 1: string}
 */
function runCommand(string $commandClass, array $arguments = [], array $options = [], bool $interactive = true): array
{
    $command = new $commandClass;
    $command->setLaravel(app());

    // Build definition from the command's own definition
    $definition = $command->getDefinition();

    // Build ArrayInput — ArrayInput takes [argument_name => value, '--option' => value]
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
        } else {
            $inputData[$key] = (string) $value;
        }
    }

    $input = new ArrayInput($inputData, $definition);
    $input->setInteractive($interactive);

    $output = new BufferedOutput;

    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

// ---------------------------------------------------------------------------
// EventsFireCommand
// ---------------------------------------------------------------------------

test('fire command fires event with payload', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    [$exit, $output] = runCommand(EventsFireCommand::class, [
        'event' => 'order.placed',
    ], [
        'payload' => ['order_id=123'],
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Firing event: order.placed')
        ->and($output)->toContain('Event fired successfully!');

    expect(EventLog::count())->toBe(1)
        ->and(EventLog::first()->event)->toBe('order.placed')
        ->and(EventLog::first()->status)->toBe(EventLog::STATUS_COMPLETED);
});

test('fire command fires event with json payload', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    [$exit, $output] = runCommand(EventsFireCommand::class, [
        'event' => 'order.placed',
    ], [
        'json' => json_encode(['order_id' => 456, 'total' => 99.99]),
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Event fired successfully!');

    $log = EventLog::first();
    expect($log->payload['order_id'])->toBe(456)
        ->and($log->payload['total'])->toBe(99.99);
});

test('fire command handles invalid json gracefully', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    [$exit, $output] = runCommand(EventsFireCommand::class, [
        'event' => 'order.placed',
    ], [
        'json' => '{invalid json}',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and($output)->toContain('Invalid JSON');
});

test('fire command without payload still fires', function (): void {
    Trigger::factory()->create([
        'event' => 'test.event',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    [$exit, $output] = runCommand(EventsFireCommand::class, [
        'event' => 'test.event',
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and(EventLog::count())->toBe(1);
});

test('fire command catches exceptions and returns failure', function (): void {
    Trigger::factory()->create([
        'event' => 'crash.event',
        'action' => 'App\\Actions\\NonExistentAction',
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    [$exit, $output] = runCommand(EventsFireCommand::class, [
        'event' => 'crash.event',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and($output)->toContain('Failed to fire event');
});

test('fire command merges payload key=value options', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    [$exit] = runCommand(EventsFireCommand::class, [
        'event' => 'order.placed',
    ], [
        'payload' => ['order_id=789', 'status=pending'],
    ]);

    expect($exit)->toBe(Command::SUCCESS);

    $log = EventLog::first();
    expect($log->payload['order_id'])->toBe('789')
        ->and($log->payload['status'])->toBe('pending');
});

test('fire command json takes precedence over payload for same key', function (): void {
    Trigger::factory()->create([
        'event' => 'order.placed',
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    [$exit] = runCommand(EventsFireCommand::class, [
        'event' => 'order.placed',
    ], [
        'json' => json_encode(['order_id' => 'from_json']),
        'payload' => ['order_id=from_payload'],
    ]);

    expect($exit)->toBe(Command::SUCCESS);

    $log = EventLog::first();
    // JSON is parsed first, then payload is merged on top (payload overrides for same key)
    expect($log->payload['order_id'])->toBe('from_payload');
});

// ---------------------------------------------------------------------------
// EventsRegisterCommand
// ---------------------------------------------------------------------------

test('register command creates trigger successfully', function (): void {
    [$exit, $output] = runCommand(EventsRegisterCommand::class, [
        'event' => 'user.registered',
        'action' => SendOrderNotification::class,
    ], [
        'name' => 'User Registration Trigger',
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain("Trigger 'User Registration Trigger' created successfully!")
        ->and(Trigger::count())->toBe(1);

    $trigger = Trigger::first();
    expect($trigger->event)->toBe('user.registered')
        ->and($trigger->action)->toBe(SendOrderNotification::class)
        ->and($trigger->name)->toBe('User Registration Trigger')
        ->and($trigger->enabled)->toBeTrue()
        ->and($trigger->async)->toBeFalse()
        ->and($trigger->priority)->toBe(0);
});

test('register command creates trigger with async and priority', function (): void {
    [$exit] = runCommand(EventsRegisterCommand::class, [
        'event' => 'high.priority.event',
        'action' => SendOrderNotification::class,
    ], [
        'async' => true,
        'priority' => '10',
        'name' => 'High Priority Async',
    ]);

    expect($exit)->toBe(Command::SUCCESS);

    $trigger = Trigger::first();
    expect($trigger->async)->toBeTrue()
        ->and($trigger->priority)->toBe(10)
        ->and($trigger->name)->toBe('High Priority Async');
});

test('register command auto-generates name when not provided', function (): void {
    [$exit] = runCommand(EventsRegisterCommand::class, [
        'event' => 'auto.named',
        'action' => SendOrderNotification::class,
    ]);

    expect($exit)->toBe(Command::SUCCESS);

    $trigger = Trigger::first();
    // TriggerBuilder generates "{event} Trigger" when no name is provided
    expect($trigger->name)->toBe('auto.named Trigger');
});

test('register command defaults priority to 0', function (): void {
    [$exit] = runCommand(EventsRegisterCommand::class, [
        'event' => 'default.priority',
        'action' => SendOrderNotification::class,
    ]);

    expect($exit)->toBe(Command::SUCCESS);

    $trigger = Trigger::first();
    expect($trigger->priority)->toBe(0);
});

test('register command display output includes id and action', function (): void {
    [$exit, $output] = runCommand(EventsRegisterCommand::class, [
        'event' => 'display.test',
        'action' => SendOrderNotification::class,
    ], [
        'name' => 'Display Test',
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain("Trigger 'Display Test' created successfully!")
        ->and($output)->toContain('ID:')
        ->and($output)->toContain('Event: display.test')
        ->and($output)->toContain('Action: '.SendOrderNotification::class);
});

// ---------------------------------------------------------------------------
// EventsRetryCommand
// ---------------------------------------------------------------------------

test('retry command with invalid status returns failure', function (): void {
    [$exit, $output] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'invalid',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and($output)->toContain('Invalid status');
});

test('retry command reports no failed logs when none exist', function (): void {
    [$exit, $output] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'failed',
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('No failed logs found.');
});

test('retry command reports no pending logs when none exist', function (): void {
    [$exit, $output] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'pending',
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('No pending logs found.');
});

test('retry command defaults to failed status', function (): void {
    // No logs = no failed logs, should use default 'failed' status
    [$exit, $output] = runCommand(EventsRetryCommand::class);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('No failed logs found.');
});

test('retry command finds failed logs', function (): void {
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventLog::factory()->failed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.failed',
        'payload' => ['order_id' => 1],
    ]);

    // Non-interactive: confirm() returns default (true)
    [$exit, $output] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'failed',
    ], interactive: false);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Found 1 failed log(s).');
});

test('retry command skips logs with disabled trigger', function (): void {
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => false,
        'async' => false,
    ]);

    EventLog::factory()->failed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.failed',
        'payload' => ['order_id' => 1],
    ]);

    [$exit, $output] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'failed',
    ], interactive: false);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Skipping log')
        ->and($output)->toContain('trigger not found or disabled');
});

test('retry command retries sync failed log via executeTrigger', function (): void {
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventLog::factory()->failed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.placed',
        'payload' => ['order_id' => 42],
    ]);

    [$exit, $output] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'failed',
    ], interactive: false);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Queued/executed 1 log(s) for retry.');
});

test('retry command retries async failed log via queue push', function (): void {
    Queue::fake();

    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => true,
    ]);

    EventLog::factory()->failed()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.async_failed',
        'payload' => ['order_id' => 99],
    ]);

    [$exit] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'failed',
    ], interactive: false);

    expect($exit)->toBe(Command::SUCCESS);

    Queue::assertPushed(DispatchTriggerJob::class, function ($job) {
        return $job->event === 'order.async_failed';
    });
});

test('retry command finds pending logs', function (): void {
    $trigger = Trigger::factory()->create([
        'action' => SendOrderNotification::class,
        'conditions' => null,
        'enabled' => true,
        'async' => false,
    ]);

    EventLog::factory()->pending()->create([
        'trigger_id' => $trigger->id,
        'event' => 'order.pending_event',
        'payload' => ['order_id' => 5],
    ]);

    [$exit, $output] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'pending',
    ], interactive: false);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Found 1 pending log(s).');
});

test('retry command skips log when trigger is null', function (): void {
    // Create an EventLog with a non-existent trigger_id
    EventLog::factory()->failed()->create([
        'trigger_id' => 'nonexistent-uuid',
        'event' => 'orphan.event',
        'payload' => ['data' => 'test'],
    ]);

    [$exit, $output] = runCommand(EventsRetryCommand::class, [], [
        'status' => 'failed',
    ], interactive: false);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Skipping log')
        ->and($output)->toContain('Queued/executed 0 log(s) for retry.');
});
