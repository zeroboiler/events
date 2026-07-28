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

    public function handle(): int
    {
        $rawId = $this->argument('id');
        $id = is_string($rawId) ? $rawId : '';

        $manager = app(EventManager::class);
        if (! $manager instanceof EventManager) {
            $this->error('EventManager not found in container.');

            return Command::FAILURE;
        }

        if ($manager->unsubscribe($id)) {
            $this->info('✅ Subscription '.$id.' removed successfully.');

            return Command::SUCCESS;
        }

        $this->error('Subscription '.$id.' not found.');

        return Command::FAILURE;
    }
}
