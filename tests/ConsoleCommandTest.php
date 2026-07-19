<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

/*
|--------------------------------------------------------------------------
| EventsFireCommand tests (#11)
|--------------------------------------------------------------------------
*/
describe('EventsFireCommand', function (): void {
    it('fires an event with no payload', function (): void {
        Trigger::factory()->create([
            'event' => 'test.fire',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        $command = app(EventsFireCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput(['event' => 'test.fire'], $command->getDefinition());
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(0)
            ->and(EventLog::count())->toBe(1);

        $outputText = $output->fetch();
        expect($outputText)->toContain('Firing event: test.fire')
            ->and($outputText)->toContain('Event fired successfully');
    });

    it('fires an event with key=value payload', function (): void {
        Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        $command = app(EventsFireCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput([
            'event' => 'order.placed',
            '--payload' => ['order_id=123'],
        ], $command->getDefinition());
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(0);

        $log = EventLog::first();
        expect($log)->not->toBeNull()
            ->and($log->payload['order_id'])->toBe('123');
    });

    it('fires an event with JSON payload', function (): void {
        Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        $command = app(EventsFireCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput([
            'event' => 'order.placed',
            '--json' => '{"order_id":456,"amount":100}',
        ], $command->getDefinition());
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(0);

        $log = EventLog::first();
        expect($log)->not->toBeNull()
            ->and($log->payload['order_id'])->toBe(456)
            ->and($log->payload['amount'])->toBe(100);
    });

    it('fails on invalid JSON', function (): void {
        $command = app(EventsFireCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput([
            'event' => 'test.fire',
            '--json' => '{invalid}',
        ], $command->getDefinition());
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(1);

        $outputText = $output->fetch();
        expect($outputText)->toContain('Invalid JSON');
    });
});

/*
|--------------------------------------------------------------------------
| EventsRegisterCommand tests (#11)
|--------------------------------------------------------------------------
*/
describe('EventsRegisterCommand', function (): void {
    it('registers a new trigger', function (): void {
        $command = app(EventsRegisterCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput([
            'event' => 'user.created',
            'action' => SendOrderNotification::class,
            '--name' => 'User Created Trigger',
        ], $command->getDefinition());
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(0);

        $trigger = Trigger::where('event', 'user.created')->first();
        expect($trigger)->not->toBeNull()
            ->and($trigger->name)->toBe('User Created Trigger')
            ->and($trigger->action)->toBe(SendOrderNotification::class)
            ->and($trigger->enabled)->toBeTrue();

        $outputText = $output->fetch();
        expect($outputText)->toContain('created successfully');
    });

    it('registers with async and priority options', function (): void {
        $command = app(EventsRegisterCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput([
            'event' => 'high.priority',
            'action' => SendOrderNotification::class,
            '--async' => true,
            '--priority' => '50',
        ], $command->getDefinition());
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(0);

        $trigger = Trigger::where('event', 'high.priority')->first();
        expect($trigger)->not->toBeNull()
            ->and($trigger->async)->toBeTrue()
            ->and($trigger->priority)->toBe(50);
    });

    it('auto-generates name when not provided', function (): void {
        $command = app(EventsRegisterCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput([
            'event' => 'auto.named',
            'action' => SendOrderNotification::class,
        ], $command->getDefinition());
        $output = new BufferedOutput;
        $command->run($input, $output);

        $trigger = Trigger::where('event', 'auto.named')->first();
        expect($trigger)->not->toBeNull()
            ->and($trigger->name)->toBe('auto.named Trigger');
    });
});

/*
|--------------------------------------------------------------------------
| EventsRetryCommand tests (#11)
|--------------------------------------------------------------------------
*/
describe('EventsRetryCommand', function (): void {
    it('retries failed event logs synchronously', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'retry.test',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        $log = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'event' => 'retry.test',
            'payload' => ['data' => 'value'],
            'status' => EventLog::STATUS_FAILED,
            'error' => 'Previous error',
        ]);

        $command = app(EventsRetryCommand::class);
        $command->setLaravel(app());
        $command->setHelperSet(new HelperSet([new QuestionHelper]));

        $input = new ArrayInput(['--status' => 'failed'], $command->getDefinition());

        // Simulate "yes" on stdin for the confirm prompt
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "yes\n");
        rewind($stream);
        $input->setStream($stream);

        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(0);

        $outputText = $output->fetch();
        expect($outputText)->toContain('1 failed log(s)');

        // The retry should have re-executed the trigger
        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    it('reports no logs when none match status', function (): void {
        $command = app(EventsRetryCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput(['--status' => 'pending'], $command->getDefinition());
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(0);

        $outputText = $output->fetch();
        expect($outputText)->toContain('No pending logs found.');
    });

    it('rejects invalid status option', function (): void {
        $command = app(EventsRetryCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput(['--status' => 'invalid'], $command->getDefinition());
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(1);

        $outputText = $output->fetch();
        expect($outputText)->toContain('Invalid status');
    });

    it('skips logs with disabled triggers', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'disabled.test',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => false,
            'async' => false,
        ]);

        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'event' => 'disabled.test',
            'payload' => [],
            'status' => EventLog::STATUS_FAILED,
        ]);

        $command = app(EventsRetryCommand::class);
        $command->setLaravel(app());
        $command->setHelperSet(new HelperSet([new QuestionHelper]));

        $input = new ArrayInput(['--status' => 'failed'], $command->getDefinition());

        // Auto-confirm
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "yes\n");
        rewind($stream);
        $input->setStream($stream);

        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(0);

        $outputText = $output->fetch();
        expect($outputText)->toContain('Skipping log');
    });
});
