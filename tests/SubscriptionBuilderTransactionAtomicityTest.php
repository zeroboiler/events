<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

describe('SubscriptionBuilder Transaction Atomicity', function (): void {
    it('creates both subscription and trigger in a single transaction', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('order.placed', 'https://example.com/webhook')
            ->withSecret('whsec_test_secret_key_1234')
            ->save();

        // Subscription exists
        expect($subscription)->toBeInstanceOf(Subscription::class);
        expect($subscription->event)->toBe('order.placed');
        expect($subscription->url)->toBe('https://example.com/webhook');
        expect($subscription->active)->toBeTrue();
        expect($subscription->secret)->toBe('whsec_test_secret_key_1234');
        expect($subscription->failure_count)->toBe(0);
        expect($subscription->delivery_count)->toBe(0);

        // Corresponding trigger was also created
        $triggerExists = Trigger::where('event', 'order.placed')
            ->where('action', 'like', '%WebhookAction%')
            ->where('action', 'like', '%subscription_id%')
            ->exists();
        expect($triggerExists)->toBeTrue();

        // The trigger's action_params contain the subscription reference
        $trigger = Trigger::where('action', 'like', '%' . $subscription->id . '%')->first();
        expect($trigger)->not->toBeNull();
        expect($trigger->enabled)->toBeTrue();
    });

    it('creates trigger with the correct priority from subscription builder', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('payment.received', 'https://partner.com/hooks')
            ->priority(50)
            ->save();

        $trigger = Trigger::where('action', 'like', '%' . $subscription->id . '%')->first();
        expect($trigger)->not->toBeNull();
        expect($trigger->priority)->toBe(50);
    });

    it('creates trigger with async flag from subscription builder', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('user.created', 'https://api.example.com/user')
            ->async(true)
            ->save();

        $trigger = Trigger::where('action', 'like', '%' . $subscription->id . '%')->first();
        expect($trigger)->not->toBeNull();
        expect($trigger->async)->toBeTrue();
    });

    it('passes conditions to both subscription and trigger', function (): void {
        $conditions = ['status' => 'paid', 'amount' => ['>', 100]];

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('order.completed', 'https://partner.com/hooks/completed')
            ->withFilter($conditions)
            ->save();

        expect($subscription->conditions)->toBe($conditions);

        // The trigger should also have the same conditions
        $trigger = Trigger::where('action', 'like', '%' . $subscription->id . '%')->first();
        expect($trigger)->not->toBeNull();
        expect($trigger->conditions)->toBe($conditions);
    });

    it('auto-generates secret when none provided and auto_generate_secret is true', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('item.updated', 'https://webhook.example.com/item')
            ->save();

        expect($subscription->secret)->not->toBeNull();
        expect($subscription->secret)->toStartWith('whsec_');
        expect(strlen((string) $subscription->secret))->toBeGreaterThanOrEqual(16 + 6); // whsec_ prefix + min 16 chars
    });

    it('respects secret_length from config', function (): void {
        config(['events.subscriptions.secret_length' => 24]);

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('config.test', 'https://example.com/config')
            ->save();

        expect($subscription->secret)->toStartWith('whsec_');
        // 6 (prefix) + 24 (secret) = 30
        expect(strlen((string) $subscription->secret))->toBe(30);
    });

    it('uses provided secret even when auto_generate_secret is true', function (): void {
        $customSecret = 'whsec_custom_provided_secret_value';

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('secret.test', 'https://example.com/secret')
            ->withSecret($customSecret)
            ->save();

        expect($subscription->secret)->toBe($customSecret);
    });

    it('rejects empty event name', function (): void {
        $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('', 'https://example.com/hook')
            ->save();
    })->throws(\InvalidArgumentException::class, 'Event name is required');

    it('rejects empty URL', function (): void {
        $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.event', '')
            ->save();
    })->throws(\InvalidArgumentException::class, 'Webhook URL is required');

    it('rejects invalid URL', function (): void {
        $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.event', 'not-a-url')
            ->save();
    })->throws(\InvalidArgumentException::class, 'valid URL');

    it('rejects non-HTTP scheme URLs', function (): void {
        $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.event', 'ftp://malicious.com/payload')
            ->save();
    })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

    it('rejects file:// scheme URLs', function (): void {
        $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.event', 'file:///etc/passwd')
            ->save();
    })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

    it('rejects short secrets (less than 16 characters)', function (): void {
        $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('test.event', 'https://example.com/hook')
            ->withSecret('short')
            ->save();
    })->throws(\InvalidArgumentException::class, 'at least 16 characters');

    it('creates subscription with null conditions when no filter is set', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('no.filter', 'https://example.com/nofilter')
            ->save();

        // conditions should be null when not set (SubscriptionBuilder saves null for empty)
        expect($subscription->conditions)->toBeNull();
    });

    it('trigger name includes event and URL for traceability', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('order.placed', 'https://example.com/webhook')
            ->save();

        $trigger = Trigger::where('action', 'like', '%' . $subscription->id . '%')->first();
        expect($trigger)->not->toBeNull();
        expect($trigger->name)->toBe('Subscription: order.placed → https://example.com/webhook');
    });

    it('allows HTTPS URLs', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('secure.event', 'https://secure.example.com/webhook')
            ->save();

        expect($subscription)->toBeInstanceOf(Subscription::class);
        expect($subscription->url)->toBe('https://secure.example.com/webhook');
    });

    it('allows HTTP URLs', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('insecure.event', 'http://internal.example.com/webhook')
            ->save();

        expect($subscription)->toBeInstanceOf(Subscription::class);
        expect($subscription->url)->toBe('http://internal.example.com/webhook');
    });

    it('subscription id is a valid UUID', function (): void {
        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('uuid.test', 'https://example.com/uuid')
            ->save();

        expect($subscription->id)->not->toBeEmpty();
        // Valid UUID v4 format
        expect((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $subscription->id))->toBeTrue();
    });

    it('does not skip auto-generation when auto_generate_secret is false but secret is provided', function (): void {
        config(['events.subscriptions.auto_generate_secret' => false]);

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('no.auto.gen', 'https://example.com/noauto')
            ->withSecret('whsec_manually_provided_secret_16')
            ->save();

        expect($subscription->secret)->toBe('whsec_manually_provided_secret_16');
    });

    it('allows null secret when auto_generate_secret is false and no secret provided', function (): void {
        config(['events.subscriptions.auto_generate_secret' => false]);

        $subscription = $this->app->make(\ZeroBoiler\Events\EventManager::class)
            ->subscribe('no.secret', 'https://example.com/nosecret')
            ->save();

        // When auto-generate is disabled and no secret provided, secret should be null
        expect($subscription->secret)->toBeNull();
    });
});
