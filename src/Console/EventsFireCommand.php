<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use ZeroBoiler\Events\EventManager;

final class EventsFireCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:fire
                           {event : The event name}
                           {--payload=* : Key=value pairs for payload}
                           {--json= : JSON string (or @file path) for complex/nested payloads}';

    /** @var string */
    protected $description = 'Manually fire an event';

    public function handle(EventManager $eventManager): int
    {
        $event = (string) $this->argument('event');

        $payload = [];

        // Process --json option if provided (takes precedence over --payload)
        $jsonOption = $this->option('json');
        if ($jsonOption !== null && $jsonOption !== '') {
            $jsonPayload = $this->parseJsonOption((string) $jsonOption);

            if ($jsonPayload === null) {
                $this->error('Invalid JSON provided to --json');

                return Command::FAILURE;
            }

            $payload = $jsonPayload;
        }

        // Merge in --payload key=value pairs (json takes precedence for keys)
        $payloadOptions = $this->option('payload');
        if (is_array($payloadOptions)) {
            foreach ($payloadOptions as $item) {
                if (! str_contains((string) $item, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', (string) $item, 2);
                $payload[$key] = $value;
            }
        }

        $this->info("Firing event: {$event}");

        if ($payload !== []) {
            $this->info('Payload:');
            foreach ($payload as $key => $value) {
                $display = is_array($value) || is_object($value)
                    ? json_encode($value)
                    : (string) $value;
                $this->line("  {$key}: {$display}");
            }
        }

        try {
            $eventManager->fire($event, $payload);

            $this->info('Event fired successfully!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to fire event: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Parse the --json option value.
     *
     * Supports:
     * - Direct JSON string: --json='{"key":"value"}'
     * - File reference: --json=@path/to/file.json
     *
     * @return array<string, mixed>|null
     */
    protected function parseJsonOption(string $input): ?array
    {
        $jsonString = $input;

        // Support @file syntax
        if (str_starts_with($input, '@')) {
            $path = substr($input, 1);

            if (! file_exists($path)) {
                $this->error("File not found: {$path}");

                return null;
            }

            $jsonString = file_get_contents($path);

            if ($jsonString === false) {
                return null;
            }
        }

        $decoded = json_decode($jsonString, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
