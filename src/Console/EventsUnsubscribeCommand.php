<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;
/**
 * Remove an external webhook subscription by ID.
 *
 * Deletes the subscription record and its associated internal trigger
 * to prevent orphaned webhook dispatches.
 */

final class EventsUnsubscribeCommand extends Command
{
    protected string $signature = 'zeroboiler:events:unsubscribe
                           {id : The subscription ID to remove}';

    protected string $description = 'Remove an external webhook subscription';

    /**
     * Execute the command.
     */
    #[\Override]
    public function handle(EventManager $eventManager): int
    {
        $id = (string) $this->argument('id');

        if ($eventManager->unsubscribe($id)) {
            $this->info("Subscription {$id} removed successfully.");

            return Command::SUCCESS;
        }

        $this->error("Subscription {$id} not found.");

        return Command::FAILURE;
    }
}
