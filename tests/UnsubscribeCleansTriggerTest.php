<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\Subscription;

/**
 * Tests for ManagesSubscriptions::unsubscribe() trigger cleanup.
 *
 * Verifies that unsubscribing a webhook subscription also deletes
 * the associated internal trigger created by SubscriptionBuilder::save(),
 * preventing orphaned triggers from continuing to dispatch webhooks
 * after the subscription has been removed.
 */
final class UnsubscribeCleansTriggerTest extends TestCase
{
    public function test_unsubscribe_deletes_associated_trigger(): void
    {
        $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);

        // Create a subscription which also creates an internal trigger
        $subscription = $eventManager->subscribe('order.placed', 'https://example.com/webhook')
            ->withSecret('whsec_test123')
            ->save();

        // Verify the subscription was created
        expect(Subscription::find($subscription->id))->not->toBeNull();

        // Find the associated trigger (created by SubscriptionBuilder)
        $triggerCountBefore = Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$subscription->id])
            ->count();

        expect($triggerCountBefore)->toBe(1);

        // Unsubscribe
        $result = $eventManager->unsubscribe($subscription->id);
        expect($result)->toBeTrue();

        // Verify subscription is deleted
        expect(Subscription::find($subscription->id))->toBeNull();

        // Verify the associated trigger was also deleted
        $triggerCountAfter = Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$subscription->id])
            ->count();

        expect($triggerCountAfter)->toBe(0);
    }

    public function test_unsubscribe_invalidates_trigger_cache(): void
    {
        $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);

        // Populate the wildcard trigger cache using the known cache key
        $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
        Cache::put($cacheKey, collect(), 300);
        expect(Cache::has($cacheKey))->toBeTrue();

        // Create and unsubscribe a subscription
        $subscription = $eventManager->subscribe('order.placed', 'https://example.com/webhook')
            ->save();

        $eventManager->unsubscribe($subscription->id);

        // Cache should be invalidated
        expect(Cache::has($cacheKey))->toBeFalse();
    }

    public function test_unsubscribe_nonexistent_returns_false(): void
    {
        $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);

        $result = $eventManager->unsubscribe('nonexistent-id');

        expect($result)->toBeFalse();
    }

    public function test_unsubscribe_does_not_delete_other_triggers(): void
    {
        $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);

        // Create a subscription
        $subscription = $eventManager->subscribe('order.placed', 'https://example.com/webhook')
            ->save();

        // Create an unrelated trigger
        $unrelatedTrigger = Trigger::factory()->enabled()->create([
            'event' => 'user.created',
            'action' => 'App\\Actions\\SomeAction',
        ]);

        // Unsubscribe
        $eventManager->unsubscribe($subscription->id);

        // Unrelated trigger should still exist
        expect(Trigger::find($unrelatedTrigger->id))->not->toBeNull();
    }

    public function test_unsubscribe_multiple_subscriptions_same_event(): void
    {
        $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);

        // Create two subscriptions for the same event
        $sub1 = $eventManager->subscribe('order.placed', 'https://app1.com/webhook')
            ->save();

        $sub2 = $eventManager->subscribe('order.placed', 'https://app2.com/webhook')
            ->save();

        // Each should have its own trigger
        $triggersForSub1 = Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$sub1->id])
            ->count();
        $triggersForSub2 = Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$sub2->id])
            ->count();

        expect($triggersForSub1)->toBe(1);
        expect($triggersForSub2)->toBe(1);

        // Unsubscribe sub1 only
        $eventManager->unsubscribe($sub1->id);

        // sub1's trigger should be deleted
        expect(Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$sub1->id])
            ->count())->toBe(0);

        // sub2's trigger should still exist
        expect(Trigger::where('action', 'like', '%WebhookAction%')
            ->whereRaw("JSON_EXTRACT(action, '$.params.subscription_id') = ?", [$sub2->id])
            ->count())->toBe(1);

        // sub2 should still exist
        expect(Subscription::find($sub2->id))->not->toBeNull();
    }
}
