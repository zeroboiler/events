<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

describe('EventsLogCommand', function (): void {
    it('displays no logs message when table is empty', function (): void {
        $command = new EventsLogCommand;
        $command->setLaravel(app());

        $result = $command->handle();

        expect($result)->toBe(0);
    });

    it('displays event logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        EventLog::factory()->completed()->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
        ]);
        EventLog::factory()->failed()->create([
            'trigger_id' => $trigger->id,
            'event' => 'order.placed',
        ]);

        $command = new EventsLogCommand;
        $command->setLaravel(app());

        $result = $command->handle();

        expect($result)->toBe(0);
    });

    it('filters by trigger ID', function (): void {
        $trigger1 = Trigger::factory()->enabled()->create();
        $trigger2 = Trigger::factory()->enabled()->create();

        EventLog::factory()->completed()->create(['trigger_id' => $trigger1->id]);
        EventLog::factory()->completed()->create(['trigger_id' => $trigger2->id]);

        $command = new EventsLogCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--trigger' => $trigger1->id,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('filters by status', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        EventLog::factory()->completed()->create(['trigger_id' => $trigger->id]);
        EventLog::factory()->failed()->create(['trigger_id' => $trigger->id]);

        $command = new EventsLogCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--status' => 'failed',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('rejects invalid status', function (): void {
        $command = new EventsLogCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--status' => 'invalid_status',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(1);
    });

    it('respects limit option', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        EventLog::factory()->count(10)->completed()->create(['trigger_id' => $trigger->id]);

        $command = new EventsLogCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--limit' => 3,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });
});
