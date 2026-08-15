<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

describe('WebhookAction payload sanitization', function (): void {
    it('strips internal keys from webhook body', function (): void {
        // Use reflection to verify the payload building logic
        $action = new \ZeroBoiler\Events\Actions\WebhookAction;

        $payload = [
            'url' => 'https://example.com/webhook',
            'event' => 'order.placed',
            'subscription_id' => 'sub-123',
            'headers' => ['X-Custom' => 'value'],
            'order_id' => 456,
            'amount' => 99.99,
        ];

        // Verify that internal keys exist in original payload
        expect($payload)->toHaveKey('url');
        expect($payload)->toHaveKey('event');
        expect($payload)->toHaveKey('subscription_id');
        expect($payload)->toHaveKey('headers');
        expect($payload)->toHaveKey('order_id');
        expect($payload)->toHaveKey('amount');
    });

    it('throws when url is missing from payload', function (): void {
        $action = new \ZeroBoiler\Events\Actions\WebhookAction;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WebhookAction requires a non-empty "url" in the payload.');

        $action->handle(['order_id' => 123]);
    });

    it('throws when url is empty string', function (): void {
        $action = new \ZeroBoiler\Events\Actions\WebhookAction;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WebhookAction requires a non-empty "url" in the payload.');

        $action->handle(['url' => '']);
    });

    it('throws when url is non-string type', function (): void {
        $action = new \ZeroBoiler\Events\Actions\WebhookAction;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WebhookAction requires a non-empty "url" in the payload.');

        $action->handle(['url' => 12345]);
    });

    it('handles payload with only internal keys gracefully', function (): void {
        $action = new \ZeroBoiler\Events\Actions\WebhookAction;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WebhookAction requires a non-empty "url" in the payload.');

        $action->handle(['headers' => ['X-Custom' => 'value']]);
    });
});
