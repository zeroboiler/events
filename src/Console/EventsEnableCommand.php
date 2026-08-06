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
        $id = (string) $this->argument('id');

        $trigger = Trigger::find($id);

        if (! $trigger) {
            $this->error("Trigger '{$id}' not found.");

            return Command::FAILURE;
        }

        if ($trigger->enabled) {
            $this->info("Trigger '{$trigger->name}' is already enabled.");

            return Command::SUCCESS;
        }

        app(EventManager::class)->enable($id);

        $this->info("Trigger '{$trigger->name}' enabled successfully.");

        return Command::SUCCESS;
    }
}
