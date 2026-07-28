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
        $rawLogId = $this->argument('log_id');
        $logId = is_string($rawLogId) ? $rawLogId : '';

        $log = EventLog::with('trigger')->find($logId);

        if ($log === null) {
            $this->error('Event log '.$logId.' not found.');

            return Command::FAILURE;
        }

        if ($log->status !== EventLog::STATUS_FAILED && $log->status !== EventLog::STATUS_COMPLETED) {
            $logStatus = is_string($log->status) ? $log->status : '';
            $this->error('Event log '.$logId." has status '".$logStatus."'. Only failed or completed logs can be redelivered.");

            return Command::FAILURE;
        }

        // Find the subscription associated with this trigger (if any)
        $payload = $log->payload;
        $subscriptionId = $payload['subscription_id'] ?? null;
        $url = $payload['url'] ?? null;

        if ($url === null) {
            $this->error('No webhook URL found in the event log payload.');

            return Command::FAILURE;
        }

        if (! $this->option('force')) {
            $logEvent = is_string($log->event) ? $log->event : '';
            $this->warn("About to redeliver webhook for event '".$logEvent."' to: ".(string) $url);
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

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post(is_string($url) ? $url : '', $body);

            if ($response->successful()) {
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $log->markAsCompleted($durationMs);

                if ($subscriptionId !== null && is_string($subscriptionId)) {
                    Subscription::find($subscriptionId)?->recordDelivery();
                }

                $this->info('✅ Webhook redelivered successfully to '.(string) $url.' (HTTP '.(string) $response->status().').');

                return Command::SUCCESS;
            }

            $this->error('Webhook redelivery returned HTTP '.(string) $response->status().': '.(string) $response->body());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Redelivery failed: '.$e->getMessage());

            $urlStr = is_string($url) ? $url : '';
            Log::error('Webhook redelivery failed', [
                'log_id' => $logId,
                'url' => $urlStr,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
