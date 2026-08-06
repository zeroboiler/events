<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Models\Subscription;

beforeEach(function (): void {
    Subscription::query()->delete();
});

describe('WebhookAction', function (): void {
    test('throws exception when payload has no url', function (): void {
        $action = new WebhookAction;

        expect(fn () => $action->handle(['data' => 'something']))
            ->toThrow(InvalidArgumentException::class, 'WebhookAction requires a non-empty "url"');
    });

    test('throws exception when payload url is empty string', function (): void {
        $action = new WebhookAction;

        expect(fn () => $action->handle(['url' => '']))
            ->toThrow(InvalidArgumentException::class, 'WebhookAction requires a non-empty "url"');
    });

    test('dispatches POST to webhook URL with correct body structure', function (): void {
        Http::fake([
            'https://example.com/webhook' => Http::response([], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'order.created',
            'order_id' => 42,
        ]);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://example.com/webhook'
                && $request->method() === 'POST'
                && isset($body['event'], $body['data'], $body['timestamp'])
                && $body['event'] === 'order.created';
        });
    });

    test('removes internal keys from webhook data', function (): void {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'test.event',
            'internal_key' => 'should_not_appear',
            'subscription_id' => 'should_not_appear',
            'headers' => ['X-Custom' => 'value'],
            'clean_data' => 'stays',
        ]);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data()['data'] ?? [];

            return ! array_key_exists('url', $data)
                && ! array_key_exists('event', $data)
                && ! array_key_exists('headers', $data)
                && ! array_key_exists('subscription_id', $data)
                && ($data['clean_data'] ?? null) === 'stays';
        });
    });

    test('signs payload with HMAC when subscription has secret', function (): void {
        $subscription = Subscription::factory()->create([
            'event' => 'order.created',
            'url' => 'https://example.com/webhook',
            'secret' => 'whsec_test_secret_key_12345',
            'active' => true,
        ]);

        Http::fake([
            'https://example.com/webhook' => Http::response([], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'order.created',
            'order_id' => 42,
            'subscription_id' => $subscription->id,
        ]);

        Http::assertSent(function (Request $request): bool {
            $signatureHeader = $request->header('X-Webhook-Signature')[0] ?? '';

            return str_starts_with($signatureHeader, 'sha256=')
                && ($request->header('X-Webhook-Subscription-Id')[0] ?? '') === $subscription->id;
        });

        // Verify delivery was recorded
        $subscription->refresh();
        expect($subscription->delivery_count)->toBe(1)
            ->and($subscription->last_fired_at)->not->toBeNull();
    });

    test('does not sign payload when subscription has no secret', function (): void {
        $subscription = Subscription::factory()->create([
            'event' => 'order.created',
            'url' => 'https://example.com/webhook',
            'secret' => null,
            'active' => true,
        ]);

        Http::fake([
            'https://example.com/webhook' => Http::response([], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'order.created',
            'subscription_id' => $subscription->id,
        ]);

        Http::assertSent(function (Request $request): bool {
            $headers = $request->headers();

            return ! isset($headers['X-Webhook-Signature']);
        });
    });

    test('records failure on non-2xx response', function (): void {
        $subscription = Subscription::factory()->create([
            'event' => 'order.created',
            'url' => 'https://example.com/webhook',
            'secret' => 'whsec_test',
            'active' => true,
            'failure_count' => 0,
        ]);

        Http::fake([
            'https://example.com/webhook' => Http::response([], 500),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'order.created',
            'subscription_id' => $subscription->id,
        ]);

        $subscription->refresh();
        expect($subscription->failure_count)->toBe(1);
    });

    test('auto-deactivates subscription after exceeding max failures', function (): void {
        config()->set('events.subscriptions.max_failures', 3);

        $subscription = Subscription::factory()->create([
            'event' => 'order.created',
            'url' => 'https://example.com/webhook',
            'secret' => 'whsec_test',
            'active' => true,
            'failure_count' => 2, // One more failure will exceed threshold
        ]);

        Http::fake([
            'https://example.com/webhook' => Http::response([], 500),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'order.created',
            'subscription_id' => $subscription->id,
        ]);

        $subscription->refresh();
        expect($subscription->failure_count)->toBe(3)
            ->and($subscription->active)->toBeFalse();
    });

    test('records failure and re-throws on HTTP exception', function (): void {
        $subscription = Subscription::factory()->create([
            'event' => 'order.created',
            'url' => 'https://example.com/webhook',
            'secret' => 'whsec_test',
            'active' => true,
            'failure_count' => 0,
        ]);

        Http::fake(function (): void {
            throw new \RuntimeException('Connection refused');
        });

        $action = new WebhookAction;

        expect(fn () => $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'order.created',
            'subscription_id' => $subscription->id,
        ]))->toThrow(\RuntimeException::class, 'Connection refused');

        $subscription->refresh();
        expect($subscription->failure_count)->toBe(1);
    });

    test('tolerates non-string headers in payload', function (): void {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'test.event',
            'headers' => 12345, // Invalid type, should be normalised to []
        ]);

        Http::assertSentCount(1);
    });

    test('tolerates null subscription_id in payload', function (): void {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'test.event',
            'subscription_id' => null,
        ]);

        Http::assertSentCount(1);
    });

    test('tolerates empty subscription_id in payload', function (): void {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'test.event',
            'subscription_id' => '',
        ]);

        Http::assertSentCount(1);
    });

    test('ignores nonexistent subscription id gracefully', function (): void {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'test.event',
            'subscription_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        Http::assertSentCount(1);
    });

    test('does not record failure for subscription without id', function (): void {
        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $action = new WebhookAction;
        // Should not throw even on 500 — just log and recordSubscriptionFailure is a no-op
        $action->handle([
            'url' => 'https://example.com/webhook',
            'event' => 'test.event',
        ]);

        Http::assertSentCount(1);
    });
});
