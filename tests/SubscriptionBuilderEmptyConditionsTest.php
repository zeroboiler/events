<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Tests that SubscriptionBuilder stores empty conditions as null
 * in the database (not as an empty JSON array).
 *
 * This is important for SQL queries that check `conditions IS NULL`
 * vs `conditions = '[]'`.
 */
test('subscription with empty conditions stores null in database', function (): void {
    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);

    $subscription = $manager->subscribe('test.empty.conditions', 'https://example.com/webhook')
        ->withSecret('whsec_test_secret_key_12345678')
        ->save();

    $fresh = Subscription::find($subscription->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->conditions)->toBeNull();

    // Clean up the internal trigger
    $manager->unsubscribe($subscription->id);
});

test('subscription with non-empty conditions stores array in database', function (): void {
    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);

    $subscription = $manager->subscribe('test.with.conditions', 'https://example.com/webhook')
        ->withSecret('whsec_test_secret_key_12345678')
        ->withFilter(['status' => 'active'])
        ->save();

    $fresh = Subscription::find($subscription->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->conditions)->toBe(['status' => 'active']);

    // Clean up the internal trigger
    $manager->unsubscribe($subscription->id);
});

test('subscription with when() creates internal trigger with same conditions', function (): void {
    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);

    $conditions = ['priority' => ['>', 5]];

    $subscription = $manager->subscribe('test.condition.match', 'https://example.com/hook')
        ->withSecret('whsec_test_secret_key_12345678')
        ->withFilter($conditions)
        ->save();

    // The internal trigger should have the same conditions
    $trigger = Trigger::where('action', 'like', '%WebhookAction%')
        ->where('event', 'test.condition.match')
        ->first();

    expect($trigger)->not->toBeNull()
        ->and($trigger->conditions)->toBe($conditions);

    // Clean up
    $manager->unsubscribe($subscription->id);
});

test('subscription without secret uses auto-generated secret of correct length', function (): void {
    config()->set('events.subscriptions.secret_length', 24);

    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);

    $subscription = $manager->subscribe('test.auto.secret', 'https://example.com/hook')
        ->save();

    $fresh = Subscription::find($subscription->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->secret)->not->toBeNull()
        ->and($fresh->secret)->toStartWith('whsec_')
        ->and(strlen((string) $fresh->secret))->toBe(6 + 24); // 'whsec_' prefix + 24 random chars

    // Clean up
    $manager->unsubscribe($subscription->id);
});

test('subscription with auto_generate_secret disabled stores null secret', function (): void {
    config()->set('events.subscriptions.auto_generate_secret', false);

    $manager = $this->app->get(ZeroBoiler\Events\EventManager::class);

    $subscription = $manager->subscribe('test.no.auto.secret', 'https://example.com/hook')
        ->save();

    $fresh = Subscription::find($subscription->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->secret)->toBeNull();

    // Clean up
    $manager->unsubscribe($subscription->id);
});
