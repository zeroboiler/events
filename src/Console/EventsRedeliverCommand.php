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
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;

/**
 * Manually redeliver a failed or completed webhook delivery.
 *
 * Re-signs the payload with the subscription's HMAC secret and
 * re-sends it to the original webhook endpoint. Supports --force
 * to skip the confirmation prompt.
 */
final class EventsRedeliverCommand extends Command
{
    use GetsWebhookTimeout;

    protected string $signature = 'zeroboiler:events:redeliver
                           {log_id : The EventLog ID of the failed delivery to redeliver}
                           {--force : Skip confirmation prompt}';

    protected string $description = 'Redeliver a failed webhook delivery';

    /**
     * Build the redeliver webhook body, stripping internal keys from
     * the payload so they don't leak to the webhook endpoint.
     *
     * Consistent with WebhookAction::handle() which removes url, event,
     * headers, and subscription_id before sending.
     *
     * @return array<string, mixed>
     *
     * @internal Not part of the public API.
     */
    private function buildRedeliverBody(EventLog $log): array
    {
        $payload = is_array($log->payload) ? $log->payload : [];

        $webhookData = $payload;
        unset($webhookData['url'], $webhookData['event'], $webhookData['headers'], $webhookData['subscription_id']);

        return [
            'event' => $log->event,
            'data' => $webhookData,
            'timestamp' => Carbon::now()->toIso8601String(),
            'redelivered' => true,
            'original_log_id' => $log->id,
        ];
    }

    /**
     * Execute the redelivery command.
     *
     * Re-signs and re-sends a failed or completed webhook delivery.
     * Strips internal payload keys before redelivery to prevent leaks.
     *
     * @return int Command exit code (SUCCESS or FAILURE)
     */
    #[\Override]
    public function handle(): int
    {
        $logId = (string) $this->argument('log_id');

        $log = EventLog::with('trigger')->find($logId);

        if (! ($log instanceof EventLog)) {
            $this->error("Event log {$logId} not found.");

            return Command::FAILURE;
        }

        if ($log->status !== EventLog::STATUS_FAILED && $log->status !== EventLog::STATUS_COMPLETED) {
            $this->error("Event log {$logId} has status '{$log->status}'. Only failed or completed logs can be redelivered.");

            return Command::FAILURE;
        }

        // Find the subscription associated with this trigger (if any)
        $payload = is_array($log->payload) ? $log->payload : [];
        $subscriptionIdRaw = $payload['subscription_id'] ?? null;
        $subscriptionId = is_string($subscriptionIdRaw) ? $subscriptionIdRaw : null;
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
        // Strip internal keys from the payload so they don't leak to the
        // webhook endpoint — consistent with WebhookAction::handle().
        $body = $this->buildRedeliverBody($log);

        $headers = [];

        // Re-sign if subscription exists
        if ($subscriptionId !== null) {
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
                ->timeout($this->getWebhookTimeout())
                ->post($url, $body);

            if ($response->successful()) {
                $redeliverDuration = (int) ((microtime(true) - $redeliverStart) * 1000);
                $log->markAsCompleted($redeliverDuration);

                if ($subscriptionId !== null) {
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
