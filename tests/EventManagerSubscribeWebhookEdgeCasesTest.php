<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 *
 * Tests for EventManager::subscribeWebhook() edge cases and distinction
 * from the full subscribe() builder.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

it('creates a trigger that dispatches WebhookAction via subscribeWebhook', function (): void {
    $eventManager = app(EventManager::class);
    $triggerId = $eventManager->subscribeWebhook('order.created', 'https://example.com/hook');

    expect($triggerId)->toBeString()->not->toBeEmpty();

    $trigger = Trigger::find($triggerId);
    expect($trigger)->not->toBeNull();
    expect($trigger->event)->toBe('order.created');
    expect($trigger->enabled)->toBeTrue();
    expect($trigger->async)->toBeFalse();

    // Action should contain WebhookAction class with URL params
    expect($trigger->action)->toContain(WebhookAction::class);
    expect($trigger->action)->toContain('https://example.com/hook');
});

it('passes conditions through subscribeWebhook', function (): void {
    $eventManager = app(EventManager::class);
    $triggerId = $eventManager->subscribeWebhook(
        'payment.received',
        'https://example.com/payment',
        ['amount' => ['>', 50]],
        5,
    );

    $trigger = Trigger::find($triggerId);
    expect($trigger)->not->toBeNull();
    expect($trigger->conditions)->toBe(['amount' => ['>', 50]]);
    expect($trigger->priority)->toBe(5);
});

it('subscribeWebhook does NOT create a Subscription record (only a trigger)', function (): void {
    $eventManager = app(EventManager::class);
    $eventManager->subscribeWebhook('test.event', 'https://example.com/hook');

    // subscribeWebhook creates a trigger, NOT a subscription record
    expect(Subscription::count())->toBe(0);
    expect(Trigger::count())->toBe(1);
});

it('subscribe (full builder) creates both Subscription and Trigger records', function (): void {
    $eventManager = app(EventManager::class);
    $subscription = $eventManager->subscribe('order.placed', 'https://example.com/webhook')
        ->withSecret('whsec_test_secret')
        ->save();

    expect($subscription)->toBeInstanceOf(Subscription::class);
    expect($subscription->event)->toBe('order.placed');
    expect($subscription->url)->toBe('https://example.com/webhook');
    expect($subscription->secret)->toBe('whsec_test_secret');
    expect($subscription->active)->toBeTrue();

    // Should also have a trigger created
    expect(Trigger::count())->toBeGreaterThanOrEqual(1);
});

it('subscribeWebhook respects default priority (0)', function (): void {
    $eventManager = app(EventManager::class);
    $triggerId = $eventManager->subscribeWebhook('user.deleted', 'https://example.com/hook');

    $trigger = Trigger::find($triggerId);
    expect($trigger->priority)->toBe(0);
});

it('subscribeWebhook trigger is async when fired with async flag', function (): void {
    $eventManager = app(EventManager::class);
    $eventManager->subscribeWebhook('async.test', 'https://example.com/hook');

    // Fire with async=true — trigger should not execute sync
    $eventManager->fire('async.test', ['key' => 'value'], async: true);

    // No EventLog should be created (async jobs create them inside the job)
    $syncLogs = EventLog::where('event', 'async.test')->count();
    expect($syncLogs)->toBe(0);
});
