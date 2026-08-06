<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;

class EventsSubscribeCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:subscribe
                           {event : The event name to subscribe to (supports wildcards)}
                           {url : The webhook URL to deliver payloads to}
                           {--secret= : HMAC signing secret (auto-generated if not provided)}
                           {--filter= : JSON-encoded conditions filter}
                           {--priority=0 : Subscription priority (higher = first)}
                           {--async : Deliver asynchronously via queue}';

    /** @var string */
    protected $description = 'Register an external webhook subscription for an event';

    public function handle(EventManager $eventManager): int
    {
        $event = $this->argument('event');
        $url = $this->argument('url');
        $secret = $this->option('secret');
        $filter = $this->option('filter');
        $priority = (int) $this->option('priority');
        $async = $this->option('async') === true;

        $builder = $eventManager->subscribe((string) $event, (string) $url)
            ->priority($priority);

        if ($async) {
            $builder->async();
        }

        if ($secret !== null && $secret !== '') {
            $builder->withSecret(is_string($secret) ? $secret : '');
        }

        if ($filter !== null && $filter !== '') {
            $filterString = is_string($filter) ? $filter : '';
            $conditions = json_decode($filterString, true);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                $this->error('Invalid JSON in --filter option: '.json_last_error_msg());

                return Command::FAILURE;
            }

            if (is_array($conditions)) {
                /** @var array<string, mixed> $conditions */
                $builder->withFilter($conditions);
            }
        }

        try {
            $subscription = $builder->save();

            $this->info('Webhook subscription created successfully!');
            $this->line('');
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $subscription->id],
                    ['Event', $subscription->event],
                    ['URL', $subscription->url],
                    ['Secret', $subscription->secret ?? '(none)'],
                    ['Priority', (string) $subscription->priority],
                    ['Active', $subscription->active ? 'Yes' : 'No'],
                ]
            );
            $this->line('');
            $this->comment('The webhook URL will receive signed POST requests when the event fires.');
            $this->comment('Verify payloads using the X-Webhook-Signature header (sha256=...).');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to create subscription: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
