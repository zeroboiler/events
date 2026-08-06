<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

describe('EventsSubscribeCommand', function (): void {
    it('creates a subscription with auto-generated secret', function (): void {
        $command = new EventsSubscribeCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                'event' => 'order.placed',
                'url' => 'https://example.com/webhook',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
        expect(Subscription::count())->toBe(1);
        expect(Trigger::where('event', 'order.placed')->exists())->toBeTrue();
    });

    it('creates a subscription with explicit secret', function (): void {
        $command = new EventsSubscribeCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                'event' => 'order.placed',
                'url' => 'https://example.com/webhook',
                '--secret' => 'whsec_test_secret_value',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);

        $sub = Subscription::first();
        expect($sub->secret)->toBe('whsec_test_secret_value');
    });

    it('creates a subscription with conditions filter', function (): void {
        $command = new EventsSubscribeCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                'event' => 'order.placed',
                'url' => 'https://example.com/webhook',
                '--filter' => '{"status":"paid"}',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);

        $sub = Subscription::first();
        expect($sub->conditions)->toBe(['status' => 'paid']);
    });

    it('rejects invalid JSON in filter option', function (): void {
        $command = new EventsSubscribeCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                'event' => 'order.placed',
                'url' => 'https://example.com/webhook',
                '--filter' => '{invalid-json}',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(1);
        expect(Subscription::count())->toBe(0);
    });

    it('creates subscription with async flag', function (): void {
        $command = new EventsSubscribeCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                'event' => 'order.placed',
                'url' => 'https://example.com/webhook',
                '--async' => true,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);

        // The trigger created by the subscription should be async
        $trigger = Trigger::where('event', 'order.placed')->first();
        expect($trigger->async)->toBeTrue();
    });

    it('creates subscription with priority', function (): void {
        $command = new EventsSubscribeCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                'event' => 'order.placed',
                'url' => 'https://example.com/webhook',
                '--priority' => 50,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);

        $sub = Subscription::first();
        expect($sub->priority)->toBe(50);
    });
});
