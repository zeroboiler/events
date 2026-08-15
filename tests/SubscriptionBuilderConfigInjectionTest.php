<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

describe('SubscriptionBuilder Config Injection', function () {
    it('reads auto_generate_secret from container config instead of static Config facade', function () {
        // The default config has auto_generate_secret = true
        $trigger = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.event', 'https://example.com/webhook')
            ->save();

        expect($trigger)->toBeInstanceOf(Subscription::class);
        expect($trigger->secret)->not->toBeNull();
        expect($trigger->secret)->toMatch('/^whsec_/');
        expect(strlen($trigger->secret))->toBeGreaterThanOrEqual(16 + 6); // whsec_ + min 16 chars

        // Clean up the internal trigger
        Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$trigger->id])
            ->delete();
        $trigger->delete();
    });

    it('respects auto_generate_secret = false from container config', function () {
        // Override config to disable auto-generation
        $config = $this->app->get('config');
        if ($config instanceof Repository) {
            $config->set('events.subscriptions.auto_generate_secret', false);
        }

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.event', 'https://example.com/webhook')
            ->save();

        expect($subscription->secret)->toBeNull();

        // Clean up
        Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$subscription->id])
            ->delete();
        $subscription->delete();
    });

    it('reads secret_length from container config', function () {
        // Set custom secret length
        $config = $this->app->get('config');
        if ($config instanceof Repository) {
            $config->set('events.subscriptions.secret_length', 48);
        }

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.secret.length', 'https://example.com/webhook')
            ->save();

        // whsec_ prefix (6) + 48 random chars = 54 total
        expect($subscription->secret)->not->toBeNull();
        expect(strlen($subscription->secret))->toBe(54);

        // Clean up
        Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$subscription->id])
            ->delete();
        $subscription->delete();
    });

    it('clamps secret_length to minimum 16 when config value is too small', function () {
        $config = $this->app->get('config');
        if ($config instanceof Repository) {
            $config->set('events.subscriptions.secret_length', 5);
        }

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.short.secret', 'https://example.com/webhook')
            ->save();

        // Should fall back to default 32 when value is below minimum
        expect($subscription->secret)->not->toBeNull();
        expect(strlen($subscription->secret))->toBe(38); // whsec_ (6) + 32

        // Clean up
        Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$subscription->id])
            ->delete();
        $subscription->delete();
    });

    it('still accepts explicit secret even when auto_generate is disabled', function () {
        $config = $this->app->get('config');
        if ($config instanceof Repository) {
            $config->set('events.subscriptions.auto_generate_secret', false);
        }

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.explicit.secret', 'https://example.com/webhook')
            ->withSecret('whsec_my_custom_secret')
            ->save();

        expect($subscription->secret)->toBe('whsec_my_custom_secret');

        // Clean up
        Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$subscription->id])
            ->delete();
        $subscription->delete();
    });
});
