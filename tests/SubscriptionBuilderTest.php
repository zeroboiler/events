<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('SubscriptionBuilder edge cases', function (): void {
    test('subscribe with double wildcard pattern works', function (): void {
        $subscription = EventManagerFacade::subscribe('order.**', 'https://api.example.com/webhook')
            ->save();

        expect($subscription)
            ->toBeInstanceOf(Subscription::class)
            ->and($subscription->event)->toBe('order.**');
    });

    test('subscribe with catch-all wildcard works', function (): void {
        $subscription = EventManagerFacade::subscribe('*', 'https://api.example.com/catch-all')
            ->save();

        expect($subscription)
            ->toBeInstanceOf(Subscription::class)
            ->and($subscription->event)->toBe('*');
    });

    test('subscribe with nested conditions works', function (): void {
        $subscription = EventManagerFacade::subscribe('order.placed', 'https://api.example.com/hook')
            ->withFilter([
                'user.role' => 'admin',
                'amount' => ['>', 100],
            ])
            ->save();

        expect($subscription->conditions)->toBe([
            'user.role' => 'admin',
            'amount' => ['>', 100],
        ]);
    });

    test('subscribe creates trigger with correct conditions', function (): void {
        $conditions = ['status' => 'paid', 'amount' => ['>', 50]];
        EventManagerFacade::subscribe('order.placed', 'https://api.example.com/hook')
            ->withFilter($conditions)
            ->save();

        $trigger = Trigger::where('event', 'order.placed')->first();
        expect($trigger)->not->toBeNull();

        $actionData = json_decode((string) $trigger->action, true);
        expect($actionData['params']['conditions'] ?? null)->toBeNull();
    });

    test('subscribe with async creates async trigger', function (): void {
        EventManagerFacade::subscribe('order.placed', 'https://api.example.com/hook')
            ->async()
            ->save();

        $trigger = Trigger::where('event', 'order.placed')->first();
        expect($trigger)->not->toBeNull()
            ->and($trigger->async)->toBeTrue();
    });

    test('subscribe with high priority creates trigger with high priority', function (): void {
        EventManagerFacade::subscribe('order.placed', 'https://api.example.com/hook')
            ->priority(100)
            ->save();

        $trigger = Trigger::where('event', 'order.placed')->first();
        expect($trigger)->not->toBeNull()
            ->and($trigger->priority)->toBe(100);
    });

    test('subscribe with empty event throws exception', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->to('https://api.example.com/hook');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();
    });

    test('subscribe with empty URL throws exception', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->on('test.event');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();
    });

    test('subscribe with invalid URL throws exception', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->on('test.event')->to('not-a-url');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();
    });

    test('subscribe with ftp URL throws exception', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->on('test.event')->to('ftp://files.example.com/data');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();
    });

    test('subscribe with empty string event throws exception', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->on('')->to('https://api.example.com/hook');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();
    });

    test('subscribe stores conditions as null when empty', function (): void {
        $subscription = EventManagerFacade::subscribe('order.placed', 'https://api.example.com/hook')
            ->withFilter([])
            ->save();

        expect($subscription->conditions)->toBeNull();
    });

    test('subscribe without secret generates whsec_ prefixed secret', function (): void {
        $subscription = EventManagerFacade::subscribe('test.event', 'https://api.example.com/hook')
            ->save();

        expect($subscription->secret)
            ->toStartWith('whsec_')
            ->and(strlen((string) $subscription->secret))->toBeGreaterThan(20);
    });

    test('subscribe stores zero failure_count and delivery_count', function (): void {
        $subscription = EventManagerFacade::subscribe('test.event', 'https://api.example.com/hook')
            ->save();

        expect($subscription->failure_count)->toBe(0)
            ->and($subscription->delivery_count)->toBe(0);
    });

    test('subscribe stores active as true by default', function (): void {
        $subscription = EventManagerFacade::subscribe('test.event', 'https://api.example.com/hook')
            ->save();

        expect($subscription->active)->toBeTrue();
    });

    test('listSubscriptions with wildcard filters using LIKE', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();
        Subscription::factory()->forEvent('order.shipped')->create();
        Subscription::factory()->forEvent('user.created')->create();

        $results = EventManagerFacade::listSubscriptions('order.*');
        expect($results)->toHaveCount(2);
    });

    test('subscribeWebhook quick helper creates trigger', function (): void {
        $triggerId = EventManagerFacade::subscribeWebhook(
            'payment.received',
            'https://api.example.com/payment-hook',
            ['amount' => ['>', 0]],
            50,
        );

        expect($triggerId)->not->toBeEmpty();

        $trigger = Trigger::find($triggerId);
        expect($trigger)->not->toBeNull()
            ->and($trigger->event)->toBe('payment.received')
            ->and($trigger->priority)->toBe(50);

        $actionData = json_decode((string) $trigger->action, true);
        expect($actionData['params']['url'])->toBe('https://api.example.com/payment-hook');
    });
});
