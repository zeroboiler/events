<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Subscription;

/**
 * Tests that Subscription::signPayload() handles empty string algorithm
 * config values gracefully by falling back to 'sha256'.
 *
 * Previously, an empty-string algorithm would be passed directly to
 * hash_hmac(), which throws a ValueError.
 *
 * @since 5.98.0
 */
describe('Subscription signPayload algorithm fallback', function (): void {
    it('falls back to sha256 when algorithm config is empty string', function (): void {
        $sub = new Subscription([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'test.event',
            'url' => 'https://example.com/webhook',
            'secret' => 'whsec_test_secret_key_12345',
            'active' => true,
        ]);

        $this->app['config']->set('events.subscriptions.signature_algorithm', '');

        $signature = $sub->signPayload('{"test": true}');

        expect($signature)->not->toBeEmpty()
            ->toMatch('/^[0-9a-f]{64}$/');
    });

    it('falls back to sha256 when algorithm config is null', function (): void {
        $sub = new Subscription([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'test.event',
            'url' => 'https://example.com/webhook',
            'secret' => 'whsec_test_secret_key_12345',
            'active' => true,
        ]);

        $this->app['config']->set('events.subscriptions.signature_algorithm', null);

        $signature = $sub->signPayload('{"test": true}');

        expect($signature)->not->toBeEmpty()
            ->toMatch('/^[0-9a-f]{64}$/');
    });

    it('uses the specified algorithm when valid', function (): void {
        $sub = new Subscription([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'test.event',
            'url' => 'https://example.com/webhook',
            'secret' => 'whsec_test_secret_key_12345',
            'active' => true,
        ]);

        $this->app['config']->set('events.subscriptions.signature_algorithm', 'sha512');

        $signature = $sub->signPayload('{"test": true}');

        // sha512 produces 128 hex chars
        expect($signature)->toMatch('/^[0-9a-f]{128}$/');
    });

    it('returns empty string when secret is null', function (): void {
        $sub = new Subscription([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'test.event',
            'url' => 'https://example.com/webhook',
            'secret' => null,
            'active' => true,
        ]);

        $signature = $sub->signPayload('{"test": true}');

        expect($signature)->toBe('');
    });

    it('returns empty string when secret is empty string', function (): void {
        $sub = new Subscription([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'test.event',
            'url' => 'https://example.com/webhook',
            'secret' => '',
            'active' => true,
        ]);

        $signature = $sub->signPayload('{"test": true}');

        expect($signature)->toBe('');
    });
});
