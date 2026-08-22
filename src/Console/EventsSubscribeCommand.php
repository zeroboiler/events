<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;

/**
 * Register an external webhook subscription for an event.
 *
 * Creates a subscription with optional HMAC secret, condition filters,
 * priority, and async delivery mode.
 * @since 1.0.0
 */
final class EventsSubscribeCommand extends Command
{
    protected string $signature = 'zeroboiler:events:subscribe
                           {event : The event name to subscribe to (supports wildcards)}
                           {url : The webhook URL to deliver payloads to}
                           {--secret= : HMAC signing secret (auto-generated if not provided)}
                           {--filter= : JSON-encoded conditions filter}
                           {--priority=0 : Subscription priority (higher = first)}
                           {--async : Deliver asynchronously via queue}';

    protected string $description = 'Register an external webhook subscription for an event';

    public function handle(EventManager $eventManager): int
    {
        $event = $this->argument('event');
        if (! is_string($event)) {
            $this->error('Event name must be a string.');

            return Command::FAILURE;
        }

        $url = $this->argument('url');
        if (! is_string($url)) {
            $this->error('Webhook URL must be a string.');

            return Command::FAILURE;
        }

        $secret = $this->option('secret');
        $filter = $this->option('filter');
        $priority = (int) $this->option('priority');
        $async = $this->option('async') === true;

        $builder = $eventManager->subscribe($event, $url)
            ->priority($priority);

        if ($async) {
            $builder->async();
        }

        if (is_string($secret) && $secret !== '') {
            $builder->withSecret($secret);
        }

        if (is_string($filter) && $filter !== '') {
            $conditions = json_decode($filter, true);
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
