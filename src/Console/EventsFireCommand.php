<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;

class EventsFireCommand extends Command
{
    protected $signature = 'zeroboiler:events:fire 
                           {event : The event name}
                           {--payload=* : Key=value pairs for payload}';

    protected $description = 'Manually fire an event';

    public function handle(): int
    {
        $event = $this->argument('event');
        $payloadOptions = $this->option('payload');

        $payload = [];
        foreach ($payloadOptions as $item) {
            if (! str_contains($item, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $item, 2);
            $payload[$key] = $value;
        }

        $this->info("Firing event: {$event}");

        if (! empty($payload)) {
            $this->info('Payload:');
            foreach ($payload as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }

        try {
            app(EventManager::class)->fire($event, $payload);

            $this->info('Event fired successfully!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to fire event: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
