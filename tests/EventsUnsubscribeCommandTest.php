<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
use ZeroBoiler\Events\Models\Subscription;

describe('EventsUnsubscribeCommand', function (): void {
    it('removes an existing subscription', function (): void {
        $subscription = Subscription::factory()->active()->create();

        $command = new EventsUnsubscribeCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                'id' => $subscription->id,
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(0);
        expect(Subscription::find($subscription->id))->toBeNull();
    });

    it('returns failure for non-existent subscription', function (): void {
        $command = new EventsUnsubscribeCommand;
        $command->setLaravel(app());

        $result = $command->run(
            new Symfony\Component\Console\Input\ArrayInput([
                'id' => 'non-existent-id',
            ]),
            new Symfony\Component\Console\Output\NullOutput,
        );

        expect($result)->toBe(1);
    });

    it('uses EventManager unsubscribe method', function (): void {
        $subscription = Subscription::factory()->active()->create();

        $eventManager = app(\ZeroBoiler\Events\EventManager::class);
        $eventManager->unsubscribe($subscription->id);

        expect(Subscription::find($subscription->id))->toBeNull();
    });
});
