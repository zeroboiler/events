<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Models\Subscription;

/**
 * Triggerable action that dispatches an HTTP POST webhook
 * to an external URL when an event fires.
 *
 * When a subscription_id is present in the payload, the webhook
 * is signed with HMAC-SHA256 using the subscription's secret.
 * The signature is sent in the `X-Webhook-Signature` header.
 */
final class WebhookAction implements Triggerable
{
    /**
     * Get the webhook timeout from config.
     */
    private function getTimeout(): int
    {
        $timeout = Config::get('events.subscriptions.timeout', 30);

        return is_int($timeout) && $timeout > 0 ? $timeout : 30;
    }

    /**
     * Get the max failures from config.
     */
    private function getMaxFailures(): int
    {
        $max = Config::get('events.subscriptions.max_failures', 10);

        return is_int($max) && $max > 0 ? $max : 10;
    }

    /**
     * Handle the event payload by dispatching an HTTP POST webhook.
     *
     * The payload is expected to contain a `url` key (the webhook endpoint).
     * If a `subscription_id` is present, the webhook is signed with HMAC.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $url = $payload['url'] ?? null;

        if (! is_string($url) || $url === '') {
            Log::warning('WebhookAction invoked without a URL', ['payload_keys' => array_keys($payload)]);

            throw new \InvalidArgumentException('WebhookAction requires a non-empty "url" in the payload.');
        }

        // Extract internal keys from the payload so they don't leak into webhook data.
        $webhookData = $payload;
        $subscriptionId = $payload['subscription_id'] ?? null;
        unset($webhookData['url'], $webhookData['event'], $webhookData['headers'], $webhookData['subscription_id']);

        // Build the webhook body
        $body = [
            'event' => $payload['event'] ?? null,
            'data' => $webhookData,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        // Allow custom headers to be passed via payload
        $headers = $payload['headers'] ?? [];
        if (! is_array($headers)) {
            $headers = [];
        }

        // If a subscription exists, sign the payload with HMAC and keep
        // the reference so we can record delivery without a second query.
        $subscription = null;
        if (is_string($subscriptionId) && $subscriptionId !== '') {
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
            $response = Http::withHeaders($headers)
                ->timeout($this->getTimeout())
                ->post($url, $body);

            if (! $response->successful()) {
                Log::error('Webhook dispatch returned non-2xx status', [
                    'url' => $url,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                $this->recordSubscriptionFailure($subscriptionId);
            } elseif ($subscription !== null) {
                // Record successful delivery using the already-loaded subscription
                $subscription->recordDelivery();
            }
        } catch (Throwable $e) {
            Log::error('Webhook dispatch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            $this->recordSubscriptionFailure($subscriptionId);

            throw $e;
        }
    }

    /**
     * Record a delivery failure on the subscription and auto-deactivate
     * if the failure threshold has been exceeded.
     */
    private function recordSubscriptionFailure(?string $subscriptionId): void
    {
        if ($subscriptionId === null) {
            return;
        }

        $subscription = Subscription::find($subscriptionId);
        if ($subscription === null) {
            return;
        }

        $subscription->recordFailure();

        if ($subscription->hasExceededFailures($this->getMaxFailures())) {
            $subscription->update(['active' => false]);

            Log::warning('Webhook subscription auto-deactivated after exceeding failure threshold', [
                'subscription_id' => $subscriptionId,
                'failure_count' => $subscription->failure_count,
                'url' => $subscription->url,
            ]);
        }
    }
}
