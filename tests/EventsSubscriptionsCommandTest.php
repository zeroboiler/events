<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Models\Subscription;

describe('EventsSubscriptionsCommand', function (): void {
    it('displays no subscriptions message when table is empty', function (): void {
        $command = new EventsSubscriptionsCommand;
        $command->setLaravel(app());

        $result = $command->handle();

        expect($result)->toBe(0);
    });

    it('lists subscriptions', function (): void {
        Subscription::factory()->count(3)->active()->create();

        $command = new EventsSubscriptionsCommand;
        $command->setLaravel(app());

        $result = $command->handle();

        expect($result)->toBe(0);
    });

    it('filters by event name', function (): void {
        Subscription::factory()->create(['event' => 'order.placed']);
        Subscription::factory()->create(['event' => 'user.created']);

        $command = new EventsSubscriptionsCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--event' => 'order.placed',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('filters active subscriptions only', function (): void {
        Subscription::factory()->count(3)->active()->create();
        Subscription::factory()->count(2)->inactive()->create();

        $command = new EventsSubscriptionsCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--active' => true,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('filters inactive subscriptions only', function (): void {
        Subscription::factory()->count(2)->active()->create();
        Subscription::factory()->count(1)->inactive()->create();

        $command = new EventsSubscriptionsCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--inactive' => true,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('supports wildcard event filtering', function (): void {
        Subscription::factory()->create(['event' => 'order.placed']);
        Subscription::factory()->create(['event' => 'order.shipped']);
        Subscription::factory()->create(['event' => 'user.created']);

        $command = new EventsSubscriptionsCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--event' => 'order.*',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });

    it('respects pagination', function (): void {
        Subscription::factory()->count(30)->active()->create();

        $command = new EventsSubscriptionsCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                '--per-page' => 5,
                '--page' => 2,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
    });
});
