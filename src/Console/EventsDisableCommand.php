<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

class EventsDisableCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:disable {id : Trigger ID}';

    /** @var string */
    protected $description = 'Disable an event trigger';

    public function handle(EventManager $eventManager): int
    {
        $id = (string) $this->argument('id');

        $trigger = Trigger::find($id);

        if ($trigger === null) {
            $this->error("Trigger '{$id}' not found.");

            return Command::FAILURE;
        }

        if (! $trigger->enabled) {
            $this->info("Trigger '{$trigger->name}' is already disabled.");

            return Command::SUCCESS;
        }

        $eventManager->disable($id);

        $this->info("Trigger '{$trigger->name}' disabled successfully.");

        return Command::SUCCESS;
    }
}
