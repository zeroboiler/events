<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;

/**
 * Register a new event trigger from the CLI.
 *
 * Creates a trigger with the specified event name and action handler class.
 * Optional flags for async dispatch, priority, and display name.
 * @since 1.0.0
 */
final class EventsRegisterCommand extends Command
{
    protected $signature = 'zeroboiler:events:register
                           {event : The event name}
                           {action : The action handler class FQN}
                           {--name= : Trigger name}
                           {--async : Dispatch asynchronously}
                           {--priority=0 : Trigger priority (higher first)}';

    protected $description = 'Register a new event trigger';

    public function handle(EventManager $eventManager): int
    {
        $event = $this->argument('event');
        if (! is_string($event)) {
            $this->error('Event name must be a string.');

            return Command::FAILURE;
        }

        $action = $this->argument('action');
        if (! is_string($action)) {
            $this->error('Action class must be a string.');

            return Command::FAILURE;
        }

        $name = $this->option('name');
        $async = $this->option('async') === true;
        $priority = (int) $this->option('priority');

        $builder = $eventManager->on($event);

        if ($name !== null && $name !== '') {
            $builder->name((string) $name);
        }

        $builder->action($action)->async($async)->priority($priority);

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
