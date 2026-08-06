<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;

class EventsUnsubscribeCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:unsubscribe
                           {id : The subscription ID to remove}';

    /** @var string */
    protected $description = 'Remove an external webhook subscription';

    public function handle(EventManager $eventManager): int
    {
        $id = $this->argument('id');

        if ($eventManager->unsubscribe((string) $id)) {
            $this->info("Subscription {$id} removed successfully.");

            return Command::SUCCESS;
        }

        $this->error("Subscription {$id} not found.");

        return Command::FAILURE;
    }
}
