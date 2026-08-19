<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Subscription;

describe('SubscriptionBuilder secret auto-generation config edge cases', function (): void {
    test('save() with auto_generate_secret=false and no withSecret() creates subscription with null secret', function (): void {
        // Override config to disable auto-generation
        config(['events.subscriptions.auto_generate_secret' => false]);

        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $subscription = $manager->subscribe('secret.test', 'https://example.com/webhook')
            ->save();

        expect($subscription)->toBeInstanceOf(Subscription::class);
        expect($subscription->secret)->toBeNull();

        // Clean up: delete the trigger created by SubscriptionBuilder
        \ZeroBoiler\Events\Models\Trigger::where('action', 'like', '%subscription_id%')
            ->where('action', 'like', '%' . $subscription->id . '%')
            ->delete();
        $subscription->delete();

        // Restore default
        config(['events.subscriptions.auto_generate_secret' => true]);
    });

    test('save() with auto_generate_secret=true (default) generates a secret with whsec_ prefix', function (): void {
        config(['events.subscriptions.auto_generate_secret' => true]);

        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $subscription = $manager->subscribe('secret.auto.test', 'https://example.com/hook')
            ->save();

        expect($subscription->secret)->not->toBeNull();
        expect($subscription->secret)->toStartWith('whsec_');
        expect(strlen((string) $subscription->secret))->toBeGreaterThan(16);

        // Clean up
        \ZeroBoiler\Events\Models\Trigger::where('action', 'like', '%subscription_id%')
            ->where('action', 'like', '%' . $subscription->id . '%')
            ->delete();
        $subscription->delete();
    });

    test('save() with explicit withSecret() overrides auto_generate_secret config', function (): void {
        config(['events.subscriptions.auto_generate_secret' => false]);

        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $subscription = $manager->subscribe('secret.explicit.test', 'https://example.com/hook2')
            ->withSecret('my_custom_secret_key_12345')
            ->save();

        expect($subscription->secret)->toBe('my_custom_secret_key_12345');

        // Clean up
        \ZeroBoiler\Events\Models\Trigger::where('action', 'like', '%subscription_id%')
            ->where('action', 'like', '%' . $subscription->id . '%')
            ->delete();
        $subscription->delete();

        // Restore default
        config(['events.subscriptions.auto_generate_secret' => true]);
    });
});
