<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Models\Subscription;

describe('Subscription secret_length config', function (): void {
    it('auto-generates secret with default length (32)', function (): void {
        Config::set('events.subscriptions.auto_generate_secret', true);
        Config::set('events.subscriptions.secret_length', 32);

        $subscription = EventManager::subscribe('test.event', 'https://example.com/webhook')
            ->save();

        expect($subscription->secret)->not->toBeNull();
        expect($subscription->secret)->toStartWith('whsec_');
        // 7 chars prefix + 32 random = 39 total
        expect(strlen((string) $subscription->secret))->toBe(39);

        // Clean up trigger + subscription
        EventManager::unsubscribe($subscription->id);
    });

    it('auto-generates secret with custom length (48)', function (): void {
        Config::set('events.subscriptions.auto_generate_secret', true);
        Config::set('events.subscriptions.secret_length', 48);

        $subscription = EventManager::subscribe('test.custom', 'https://example.com/custom')
            ->save();

        expect($subscription->secret)->not->toBeNull();
        expect($subscription->secret)->toStartWith('whsec_');
        // 7 chars prefix + 48 random = 55 total
        expect(strlen((string) $subscription->secret))->toBe(55);

        EventManager::unsubscribe($subscription->id);
    });

    it('uses provided secret when explicitly set (ignores secret_length)', function (): void {
        Config::set('events.subscriptions.secret_length', 32);

        $subscription = EventManager::subscribe('test.explicit', 'https://example.com/explicit')
            ->withSecret('my_custom_secret_value')
            ->save();

        expect($subscription->secret)->toBe('my_custom_secret_value');

        EventManager::unsubscribe($subscription->id);
    });

    it('falls back to 32 when secret_length is too small (< 16)', function (): void {
        Config::set('events.subscriptions.auto_generate_secret', true);
        Config::set('events.subscriptions.secret_length', 8);

        $subscription = EventManager::subscribe('test.short', 'https://example.com/short')
            ->save();

        // Should fall back to 32 (default) since 8 < 16
        expect($subscription->secret)->not->toBeNull();
        expect(strlen((string) $subscription->secret))->toBe(39); // 7 + 32

        EventManager::unsubscribe($subscription->id);
    });

    it('falls back to 32 when secret_length is non-integer', function (): void {
        Config::set('events.subscriptions.auto_generate_secret', true);
        Config::set('events.subscriptions.secret_length', 'not-a-number');

        $subscription = EventManager::subscribe('test.nonint', 'https://example.com/nonint')
            ->save();

        expect($subscription->secret)->not->toBeNull();
        expect(strlen((string) $subscription->secret))->toBe(39); // 7 + 32

        EventManager::unsubscribe($subscription->id);
    });

    it('auto-generated secrets are unique across subscriptions', function (): void {
        Config::set('events.subscriptions.auto_generate_secret', true);
        Config::set('events.subscriptions.secret_length', 32);

        $sub1 = EventManager::subscribe('test.unique1', 'https://example.com/u1')
            ->save();
        $sub2 = EventManager::subscribe('test.unique2', 'https://example.com/u2')
            ->save();

        expect($sub1->secret)->not->toBe($sub2->secret);

        EventManager::unsubscribe($sub1->id);
        EventManager::unsubscribe($sub2->id);
    });
});
