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

    public function handle(): int
    {
        $rawEvent = $this->argument('event');
        $event = is_string($rawEvent) ? $rawEvent : '';
        $rawAction = $this->argument('action');
        $action = is_string($rawAction) ? $rawAction : '';
        $name = $this->option('name');
        $async = $this->option('async') === true;
        $rawPriority = $this->option('priority');
        $priority = is_numeric($rawPriority) ? (int) $rawPriority : 0;

        $eventManager = app(EventManager::class);
        if (! $eventManager instanceof EventManager) {
            $this->error('EventManager not found in container.');

            return Command::FAILURE;
        }

        $builder = $eventManager->on($event);

        if (is_string($name) && $name !== '') {
            $builder->name($name);
        }

        $builder->action($action)->async($async)->priority($priority);

        try {
            $trigger = $builder->save();

            $triggerName = is_string($trigger->name) ? $trigger->name : '';
            $triggerId = is_string($trigger->id) ? $trigger->id : '';
            $triggerEvent = is_string($trigger->event) ? $trigger->event : '';
            $triggerAction = is_string($trigger->action) ? $trigger->action : '';

            $this->info("Trigger '".$triggerName."' created successfully!");
            $this->line('ID: '.$triggerId);
            $this->line('Event: '.$triggerEvent);
            $this->line('Action: '.$triggerAction);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to create trigger: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
