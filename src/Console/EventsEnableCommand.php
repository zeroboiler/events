<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

class EventsEnableCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:enable {id : Trigger ID}';

    /** @var string */
    protected $description = 'Enable an event trigger';

    public function handle(): int
    {
        $rawId = $this->argument('id');
        $id = is_string($rawId) ? $rawId : '';

        $trigger = Trigger::find($id);

        if ($trigger === null) {
            $this->error("Trigger '{$id}' not found.");

            return Command::FAILURE;
        }

        $triggerName = $trigger->name;

        if ($trigger->enabled) {
            $this->info("Trigger '".$triggerName."' is already enabled.");

            return Command::SUCCESS;
        }

        $eventManager = app(EventManager::class);
        if (! $eventManager instanceof EventManager) {
            $this->error('EventManager not found in container.');

            return Command::FAILURE;
        }
        $eventManager->enable($id);

        $this->info("Trigger '".$triggerName."' enabled successfully.");

        return Command::SUCCESS;
    }
}
