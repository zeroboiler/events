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
 * Disable an active event trigger.
 * @since 1.0.0
 */
final class EventsDisableCommand extends Command
{
    protected string $signature = 'zeroboiler:events:disable {id : Trigger ID}';

    protected string $description = 'Disable an event trigger';

    #[\Override]
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
