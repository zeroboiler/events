<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Enable a previously disabled event trigger.
 */
final class EventsEnableCommand extends Command
{
    protected string $signature = 'zeroboiler:events:enable {id : Trigger ID}';

    protected string $description = 'Enable an event trigger';

    #[\Override]
    public function handle(EventManager $eventManager): int
    {
        $id = (string) $this->argument('id');

        $trigger = Trigger::find($id);

        if ($trigger === null) {
            $this->error("Trigger '{$id}' not found.");

            return Command::FAILURE;
        }

        if ($trigger->enabled) {
            $this->info("Trigger '{$trigger->name}' is already enabled.");

            return Command::SUCCESS;
        }

        $eventManager->enable($id);

        $this->info("Trigger '{$trigger->name}' enabled successfully.");

        return Command::SUCCESS;
    }
}
