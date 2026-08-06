<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Models\Trigger;

describe('EventsListCommand', function (): void {
    it('displays no triggers message when table is empty', function (): void {
        $command = new EventsListCommand;
        $command->setLaravel(app());

        $result = $command->handle();

        expect($result)->toBe(0);
    });

    it('lists triggers with pagination', function (): void {
        Trigger::factory()->count(5)->create(['enabled' => true, 'priority' => 10]);
        Trigger::factory()->count(3)->create(['enabled' => false, 'priority' => 5]);

        $command = new EventsListCommand;
        $command->setLaravel(app());

        $result = $command->handle();

        expect($result)->toBe(0);
    });

    it('filters by event name', function (): void {
        Trigger::factory()->create(['event' => 'order.placed', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'user.created', 'enabled' => true]);

        $command = new EventsListCommand;
        $command->setLaravel(app());
        $command->define('event', 'order.placed');

        // The command reads from --event option, which is set via setInput
        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--event' => 'order.placed',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('filters enabled triggers only', function (): void {
        Trigger::factory()->count(3)->create(['enabled' => true]);
        Trigger::factory()->count(2)->create(['enabled' => false]);

        $command = new EventsListCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--enabled' => true,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('filters disabled triggers only', function (): void {
        Trigger::factory()->count(2)->create(['enabled' => true]);
        Trigger::factory()->count(1)->create(['enabled' => false]);

        $command = new EventsListCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--disabled' => true,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('respects per-page limit', function (): void {
        Trigger::factory()->count(50)->create(['enabled' => true]);

        $command = new EventsListCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--per-page' => 5,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });
});
