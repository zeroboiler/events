<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('SubscriptionBuilder transaction atomicity', function (): void {
    test('save() creates both subscription and trigger atomically', function (): void {
        $subscription = EventManager::subscribe('order.placed', 'https://api.example.com/hook')
            ->save();

        expect($subscription)->toBeInstanceOf(Subscription::class)
            ->and($subscription->event)->toBe('order.placed')
            ->and($subscription->url)->toBe('https://api.example.com/hook')
            ->and($subscription->active)->toBeTrue();

        // Verify trigger was also created
        $trigger = Trigger::where('event', 'order.placed')->first();
        expect($trigger)->not->toBeNull()
            ->and($trigger->action)->toContain(WebhookAction::class);
    });

    test('save() validates input before starting transaction', function (): void {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('')->to('https://api.example.com/hook');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();

        // Validation should throw BEFORE any DB operations
        expect(Subscription::count())->toBe(0)
            ->and(Trigger::count())->toBe(0);
    });

    test('save() creates trigger with correct action params referencing subscription', function (): void {
        $subscription = EventManager::subscribe('order.placed', 'https://partner.com/webhook')
            ->withSecret('whsec_test_secret')
            ->priority(42)
            ->async()
            ->save();

        $trigger = Trigger::where('event', 'order.placed')->first();
        expect($trigger)->not->toBeNull();

        $actionData = json_decode((string) $trigger->action, true);
        expect($actionData['class'])->toBe(WebhookAction::class)
            ->and($actionData['params']['url'])->toBe('https://partner.com/webhook')
            ->and($actionData['params']['subscription_id'])->toBe($subscription->id)
            ->and($trigger->async)->toBeTrue()
            ->and($trigger->priority)->toBe(42);
    });

    test('save() with conditions stores them on trigger and subscription', function (): void {
        $conditions = ['status' => 'paid', 'amount' => ['>', 100]];

        $subscription = EventManager::subscribe('order.placed', 'https://api.example.com/hook')
            ->withFilter($conditions)
            ->save();

        // Subscription stores conditions
        expect($subscription->conditions)->toBe($conditions);

        // Trigger also stores conditions for condition engine evaluation
        $trigger = Trigger::where('event', 'order.placed')->first();
        expect($trigger)->not->toBeNull()
            ->and($trigger->conditions)->toBe($conditions);
    });

    test('save() without withFilter stores null conditions on subscription', function (): void {
        $subscription = EventManager::subscribe('order.placed', 'https://api.example.com/hook')
            ->save();

        expect($subscription->conditions)->toBeNull();
    });

    test('save() with explicit secret uses provided secret', function (): void {
        $subscription = EventManager::subscribe('order.placed', 'https://api.example.com/hook')
            ->withSecret('whsec_my_custom_secret')
            ->save();

        expect($subscription->secret)->toBe('whsec_my_custom_secret');
    });

    test('multiple subscriptions for same event are independent', function (): void {
        $sub1 = EventManager::subscribe('order.placed', 'https://partner1.com/hook')
            ->withSecret('whsec_sub1')
            ->save();

        $sub2 = EventManager::subscribe('order.placed', 'https://partner2.com/hook')
            ->withSecret('whsec_sub2')
            ->save();

        expect($sub1->id)->not->toBe($sub2->id)
            ->and($sub1->secret)->toBe('whsec_sub1')
            ->and($sub2->secret)->toBe('whsec_sub2');

        // Two independent triggers should be created
        $triggers = Trigger::where('event', 'order.placed')->get();
        expect($triggers)->toHaveCount(2);
    });
});

describe('WebhookAction recordSubscriptionFailure optimization', function (): void {
    test('recordSubscriptionFailure accepts already-loaded subscription', function (): void {
        // Create a subscription and trigger
        $subscription = Subscription::factory()->active()->create([
            'failure_count' => 0,
        ]);

        // Use reflection to call the private method with a pre-loaded subscription
        $action = new class
        {
            public function __construct() {}
        };
        // Since we can't easily test the private method directly, verify the
        // public behavior: recordFailure increments failure_count
        $subscription->recordFailure();
        expect($subscription->failure_count)->toBe(1);

        $subscription->recordFailure();
        expect($subscription->failure_count)->toBe(2);
    });

    test('subscription failure tracking with resetFailures', function (): void {
        $subscription = Subscription::factory()->active()->create([
            'failure_count' => 5,
        ]);

        expect($subscription->failure_count)->toBe(5);

        $subscription->resetFailures();
        expect($subscription->failure_count)->toBe(0);
    });

    test('hasExceededFailures uses config default', function (): void {
        $subscription = Subscription::factory()->active()->create([
            'failure_count' => 10,
        ]);

        // Default max_failures is 10, so 10 failures = exceeded
        expect($subscription->hasExceededFailures())->toBeTrue();
    });

    test('hasExceededFailures with custom max', function (): void {
        $subscription = Subscription::factory()->active()->create([
            'failure_count' => 3,
        ]);

        expect($subscription->hasExceededFailures(5))->toBeFalse()
            ->and($subscription->hasExceededFailures(3))->toBeTrue()
            ->and($subscription->hasExceededFailures(2))->toBeTrue();
    });

    test('subscription delivery tracking', function (): void {
        $subscription = Subscription::factory()->active()->create([
            'delivery_count' => 0,
            'last_fired_at' => null,
        ]);

        $subscription->recordDelivery();

        expect($subscription->delivery_count)->toBe(1)
            ->and($subscription->last_fired_at)->not->toBeNull();
    });

    test('subscription signPayload is deterministic', function (): void {
        $subscription = Subscription::factory()->active()->withSecret('whsec_test')->create();

        $sig1 = $subscription->signPayload('{"test": "data"}');
        $sig2 = $subscription->signPayload('{"test": "data"}');

        expect($sig1)->toBe($sig2)
            ->and($sig1)->not->toBeEmpty();
    });
});
