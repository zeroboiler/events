<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;

class EventsRedeliverCommand extends Command
{
    /** @var string */
    protected $signature = 'zeroboiler:events:redeliver
                           {log_id : The EventLog ID of the failed delivery to redeliver}
                           {--force : Skip confirmation prompt}';

    /** @var string */
    protected $description = 'Redeliver a failed webhook delivery';

    public function handle(): int
    {
        $logId = (string) $this->argument('log_id');

        $log = EventLog::with('trigger')->find($logId);

        if ($log === null) {
            $this->error("Event log {$logId} not found.");

            return Command::FAILURE;
        }

        assert($log instanceof EventLog);

        if ($log->status !== EventLog::STATUS_FAILED && $log->status !== EventLog::STATUS_COMPLETED) {
            $this->error("Event log {$logId} has status '{$log->status}'. Only failed or completed logs can be redelivered.");

            return Command::FAILURE;
        }

        // Find the subscription associated with this trigger (if any)
        $payload = is_array($log->payload) ? $log->payload : [];
        $subscriptionId = $payload['subscription_id'] ?? null;
        $url = $payload['url'] ?? null;

        if (! is_string($url) || $url === '') {
            $this->error('No webhook URL found in the event log payload.');

            return Command::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn("About to redeliver webhook for event '{$log->event}' to: {$url}");
            if (! $this->confirm('Continue?')) {
                $this->info('Redelivery cancelled.');

                return Command::SUCCESS;
            }
        }

        // Rebuild the webhook body
        $body = [
            'event' => $log->event,
            'data' => $payload,
            'timestamp' => Carbon::now()->toIso8601String(),
            'redelivered' => true,
            'original_log_id' => $log->id,
        ];

        $headers = [];

        // Re-sign if subscription exists
        if ($subscriptionId !== null && is_string($subscriptionId)) {
            $subscription = Subscription::find($subscriptionId);
            if ($subscription !== null) {
                $signedBody = json_encode($body, \JSON_THROW_ON_ERROR);
                $signature = $subscription->signPayload($signedBody);
                if ($signature !== '' && $signature !== '0') {
                    $headers['X-Webhook-Signature'] = 'sha256='.$signature;
                    $headers['X-Webhook-Subscription-Id'] = $subscriptionId;
                }
            }
        }

        try {
            $redeliverStart = microtime(true);
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post($url, $body);

            if ($response->successful()) {
                $redeliverDuration = (int) ((microtime(true) - $redeliverStart) * 1000);
                $log->markAsCompleted($redeliverDuration);

                if ($subscriptionId !== null && is_string($subscriptionId)) {
                    Subscription::find($subscriptionId)?->recordDelivery();
                }

                $this->info("Webhook redelivered successfully to {$url} (HTTP {$response->status()}).");

                return Command::SUCCESS;
            }

            $this->error("Webhook redelivery returned HTTP {$response->status()}: {$response->body()}");

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Redelivery failed: {$e->getMessage()}");

            Log::error('Webhook redelivery failed', [
                'log_id' => $logId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
