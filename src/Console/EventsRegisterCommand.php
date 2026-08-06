<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;

class EventsRegisterCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:register
                           {event : The event name}
                           {action : The action handler class FQN}
                           {--name= : Trigger name}
                           {--async : Dispatch asynchronously}
                           {--priority=0 : Trigger priority (higher first)}';

    /** @var string */
    protected $description = 'Register a new event trigger';

    public function handle(EventManager $eventManager): int
    {
        $event = $this->argument('event');
        $action = $this->argument('action');
        $name = $this->option('name');
        $async = $this->option('async') === true;
        $priority = (int) $this->option('priority');

        $builder = $eventManager->on((string) $event);

        if ($name !== null && $name !== '') {
            $builder->name((string) $name);
        }

        $builder->action((string) $action)->async($async)->priority($priority);

        try {
            $trigger = $builder->save();

            $this->info("Trigger '{$trigger->name}' created successfully!");
            $this->line("ID: {$trigger->id}");
            $this->line("Event: {$trigger->event}");
            $this->line("Action: {$trigger->action}");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to create trigger: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
