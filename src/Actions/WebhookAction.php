<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZeroBoiler\Events\Contracts\Triggerable;

/**
 * Triggerable action that dispatches an HTTP POST webhook
 * to an external URL when an event fires.
 */
class WebhookAction implements Triggerable
{
    /**
     * Default timeout in seconds for the HTTP request.
     */
    private const DEFAULT_TIMEOUT = 30;

    /**
     * Handle the event payload by dispatching an HTTP POST webhook.
     *
     * The payload is expected to contain a `url` key (the webhook endpoint)
     * and optionally a `data` key with additional context. The entire
     * payload is sent as JSON, with event metadata wrapped under a
     * `webhook` envelope.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $url = $payload['url'] ?? null;

        if (empty($url)) {
            Log::warning('WebhookAction invoked without a URL', ['payload' => $payload]);

            throw new \InvalidArgumentException('WebhookAction requires a non-empty "url" in the payload.');
        }

        // Extract the webhook URL from the payload, then remove
        // internal keys before sending the remainder as webhook data.
        $webhookData = $payload;
        unset($webhookData['url']);

        $body = [
            'event' => $payload['event'] ?? null,
            'data' => $webhookData,
            'timestamp' => now()->toIso8601String(),
        ];

        // Allow custom headers to be passed via payload
        $headers = $payload['headers'] ?? [];
        if (is_array($headers)) {
            unset($webhookData['headers']);
            unset($body['data']['headers']);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->post($url, $body);

            if (! $response->successful()) {
                Log::error('Webhook dispatch returned non-2xx status', [
                    'url' => $url,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Webhook dispatch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
