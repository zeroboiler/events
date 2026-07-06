<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('SubscriptionBuilder', function (): void {
    test('subscribe creates a subscription via fluent builder', function (): void {
        $subscription = EventManagerFacade::subscribe('order.placed', 'https://api.example.com/webhook')
            ->withSecret('whsec_test123')
            ->withFilter(['status' => 'paid'])
            ->priority(10)
            ->save();

        expect($subscription)
            ->toBeInstanceOf(Subscription::class)
            ->event->toBe('order.placed')
            ->url->toBe('https://api.example.com/webhook')
            ->secret->toBe('whsec_test123')
            ->priority->toBe(10)
            ->active->toBeTrue()
            ->and($subscription->conditions)->toBe(['status' => 'paid']);
    });

    test('subscribe auto-generates secret when none provided', function (): void {
        $subscription = EventManagerFacade::subscribe('user.created', 'https://api.example.com/hook')
            ->save();

        expect($subscription->secret)
            ->not->toBeNull()
            ->toStartWith('whsec_');
    });

    test('subscribe validates event name is required', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->to('https://example.com');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();
    });

    test('subscribe validates URL is required', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->on('test.event');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();
    });

    test('subscribe validates URL format', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $builder->on('test.event')->to('not-a-url');

        $this->expectException(InvalidArgumentException::class);
        $builder->save();
    });

    test('subscribe also creates an internal trigger', function (): void {
        EventManagerFacade::subscribe('order.placed', 'https://api.example.com/webhook')
            ->withSecret('whsec_test')
            ->save();

        // A trigger should have been created that references the subscription
        $trigger = Trigger::where('event', 'order.placed')->first();

        expect($trigger)
            ->not->toBeNull();

        $actionData = json_decode((string) $trigger->action, true);
        expect($actionData)
            ->toHaveKey('class')
            ->and($actionData['class'])->toBe(WebhookAction::class)
            ->and($actionData)->toHaveKey('params')
            ->and($actionData['params'])->toHaveKey('url')
            ->and($actionData['params']['url'])->toBe('https://api.example.com/webhook')
            ->and($actionData['params'])->toHaveKey('subscription_id');
    });
});

describe('Subscription Model', function (): void {
    test('subscription generates UUID on creation', function (): void {
        $subscription = Subscription::factory()->create();

        expect($subscription->id)
            ->not->toBeEmpty()
            ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
    });

    test('subscription hides secret from serialization', function (): void {
        $subscription = Subscription::factory()->withSecret('whsec_super_secret')->create();

        $array = $subscription->toArray();

        expect($array)
            ->not->toHaveKey('secret');
    });

    test('matchesEvent returns true for exact match', function (): void {
        $subscription = Subscription::factory()->forEvent('order.placed')->create();

        expect($subscription->matchesEvent('order.placed'))->toBeTrue()
            ->and($subscription->matchesEvent('order.shipped'))->toBeFalse();
    });

    test('matchesEvent handles wildcards', function (): void {
        $subscription = Subscription::factory()->forEvent('order.*')->create();

        expect($subscription->matchesEvent('order.placed'))->toBeTrue()
            ->and($subscription->matchesEvent('order.shipped'))->toBeTrue()
            ->and($subscription->matchesEvent('user.created'))->toBeFalse();
    });

    test('matchesEvent handles nested wildcards', function (): void {
        $subscription = Subscription::factory()->forEvent('order.*.created')->create();

        expect($subscription->matchesEvent('order.123.created'))->toBeTrue()
            ->and($subscription->matchesEvent('order.created'))->toBeFalse();
    });

    test('signPayload produces correct HMAC', function (): void {
        $subscription = Subscription::factory()
            ->withSecret('whsec_secret_key')
            ->create();

        $payload = '{"test":"data"}';
        $expected = hash_hmac('sha256', $payload, 'whsec_secret_key');

        expect($subscription->signPayload($payload))->toBe($expected);
    });

    test('signPayload returns empty string when no secret', function (): void {
        $subscription = Subscription::factory()->withoutSecret()->create();

        expect($subscription->signPayload('test'))->toBe('');
    });

    test('recordDelivery updates last_fired_at and delivery_count', function (): void {
        $subscription = Subscription::factory()->create([
            'last_fired_at' => null,
            'delivery_count' => 0,
        ]);

        $subscription->recordDelivery();
        $subscription->refresh();

        expect($subscription->last_fired_at)->not->toBeNull()
            ->and($subscription->delivery_count)->toBe(1);
    });

    test('recordFailure increments failure_count', function (): void {
        $subscription = Subscription::factory()->create(['failure_count' => 3]);

        $subscription->recordFailure();
        $subscription->refresh();

        expect($subscription->failure_count)->toBe(4);
    });

    test('resetFailures sets failure_count to zero', function (): void {
        $subscription = Subscription::factory()->create(['failure_count' => 5]);

        $subscription->resetFailures();
        $subscription->refresh();

        expect($subscription->failure_count)->toBe(0);
    });

    test('hasExceededFailures respects threshold', function (): void {
        $subscription = Subscription::factory()->create(['failure_count' => 10]);

        expect($subscription->hasExceededFailures(10))->toBeTrue()
            ->and($subscription->hasExceededFailures(11))->toBeFalse();
    });

    test('scopeForEvent filters by exact event', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();
        Subscription::factory()->forEvent('user.created')->create();

        $results = Subscription::forEvent('order.placed')->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->event)->toBe('order.placed');
    });

    test('scopeActive filters active subscriptions', function (): void {
        Subscription::factory()->active()->create();
        Subscription::factory()->inactive()->create();

        $results = Subscription::active()->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->active)->toBeTrue();
    });
});

describe('EventManager subscription API', function (): void {
    test('unsubscribe removes subscription by ID', function (): void {
        $subscription = Subscription::factory()->create();

        $result = EventManagerFacade::unsubscribe($subscription->id);

        expect($result)->toBeTrue()
            ->and(Subscription::find($subscription->id))->toBeNull();
    });

    test('unsubscribe returns false for non-existent ID', function (): void {
        $result = EventManagerFacade::unsubscribe('nonexistent-uuid');

        expect($result)->toBeFalse();
    });

    test('listSubscriptions returns all subscriptions', function (): void {
        Subscription::factory()->count(3)->create();

        $results = EventManagerFacade::listSubscriptions();

        expect($results)->toHaveCount(3);
    });

    test('listSubscriptions filters by event', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();
        Subscription::factory()->forEvent('user.created')->create();

        $results = EventManagerFacade::listSubscriptions('order.placed');

        expect($results)->toHaveCount(1)
            ->and($results->first()->event)->toBe('order.placed');
    });

    test('listSubscriptions with wildcard filter', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();
        Subscription::factory()->forEvent('order.shipped')->create();
        Subscription::factory()->forEvent('user.created')->create();

        $results = EventManagerFacade::listSubscriptions('order.*');

        expect($results)->toHaveCount(2);
    });

    test('listSubscriptions activeOnly filter', function (): void {
        Subscription::factory()->active()->create();
        Subscription::factory()->inactive()->create();

        $results = EventManagerFacade::listSubscriptions(null, true);

        expect($results)->toHaveCount(1)
            ->and($results->first()->active)->toBeTrue();
    });

    test('getSubscription returns subscription by ID', function (): void {
        $subscription = Subscription::factory()->create();

        $result = EventManagerFacade::getSubscription($subscription->id);

        expect($result)
            ->not->toBeNull()
            ->id->toBe($subscription->id);
    });

    test('getSubscription returns null for non-existent ID', function (): void {
        $result = EventManagerFacade::getSubscription('nonexistent');

        expect($result)->toBeNull();
    });
});

describe('WebhookAction with subscriptions', function (): void {
    test('webhook delivery with subscription includes HMAC signature', function (): void {
        Http::fake([
            'https://api.example.com/webhook' => Http::response(['ok' => true], 200),
        ]);

        $subscription = Subscription::factory()
            ->withSecret('whsec_signing_key')
            ->create();

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://api.example.com/webhook',
            'subscription_id' => $subscription->id,
            'event' => 'order.placed',
            'order_id' => 123,
        ]);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Webhook-Signature')
            && str_starts_with($request->header('X-Webhook-Signature')[0] ?? '', 'sha256=')
            && $request->hasHeader('X-Webhook-Subscription-Id'));
    });

    test('webhook delivery without subscription has no signature', function (): void {
        Http::fake([
            'https://api.example.com/webhook' => Http::response(['ok' => true], 200),
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://api.example.com/webhook',
            'event' => 'test.event',
        ]);

        Http::assertSent(fn ($request): bool => ! $request->hasHeader('X-Webhook-Signature'));
    });

    test('successful delivery records on subscription', function (): void {
        Http::fake([
            'https://api.example.com/webhook' => Http::response(['ok' => true], 200),
        ]);

        $subscription = Subscription::factory()->create([
            'delivery_count' => 0,
            'last_fired_at' => null,
        ]);

        $action = new WebhookAction;
        $action->handle([
            'url' => 'https://api.example.com/webhook',
            'subscription_id' => $subscription->id,
            'event' => 'test.event',
        ]);

        $subscription->refresh();

        expect($subscription->delivery_count)->toBe(1)
            ->and($subscription->last_fired_at)->not->toBeNull();
    });

    test('failed delivery increments failure count', function (): void {
        Http::fake([
            'https://api.example.com/fail' => Http::response(['error' => true], 500),
        ]);

        $subscription = Subscription::factory()->create([
            'failure_count' => 0,
        ]);

        $action = new WebhookAction;

        try {
            $action->handle([
                'url' => 'https://api.example.com/fail',
                'subscription_id' => $subscription->id,
                'event' => 'test.event',
            ]);
        } catch (Throwable) {
            // WebhookAction doesn't throw on non-2xx, just logs
        }

        $subscription->refresh();

        expect($subscription->failure_count)->toBe(1);
    });

    test('exception during delivery increments failure count', function (): void {
        Http::fake(function (): Response {
            throw new ConnectionException('Connection refused');
        });

        $subscription = Subscription::factory()->create([
            'failure_count' => 0,
        ]);

        $action = new WebhookAction;

        expect(fn () => $action->handle([
            'url' => 'https://api.example.com/down',
            'subscription_id' => $subscription->id,
            'event' => 'test.event',
        ]))->toThrow(ConnectionException::class);

        $subscription->refresh();

        expect($subscription->failure_count)->toBe(1);
    });

    test('subscription auto-deactivates after max failures', function (): void {
        Http::fake([
            'https://api.example.com/fail' => Http::response(['error' => true], 500),
        ]);

        $subscription = Subscription::factory()->create([
            'failure_count' => 9,
            'active' => true,
        ]);

        $action = new WebhookAction;

        $action->handle([
            'url' => 'https://api.example.com/fail',
            'subscription_id' => $subscription->id,
            'event' => 'test.event',
        ]);

        $subscription->refresh();

        expect($subscription->failure_count)->toBe(10)
            ->and($subscription->active)->toBeFalse();
    });
});

describe('End-to-end subscription flow', function (): void {
    test('subscribe + fire delivers webhook to subscription URL', function (): void {
        Http::fake([
            'https://partner.example.com/api/order-hook' => Http::response(['ok' => true], 200),
        ]);

        // Create subscription
        $sub = EventManagerFacade::subscribe('order.placed', 'https://partner.example.com/api/order-hook')
            ->withSecret('whsec_integration')
            ->save();

        // Verify trigger was created
        expect(Trigger::where('event', 'order.placed')->count())->toBe(1);

        // Fire the event
        EventManagerFacade::fire('order.placed', ['order_id' => 42, 'total' => 100]);

        // Check that an event log was created (sync dispatch)
        expect(EventLog::count())->toBeGreaterThan(0);

        // Verify webhook was called
        Http::assertSentCount(1);

        // Verify the request details
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://partner.example.com/api/order-hook'
            && $request->hasHeader('X-Webhook-Signature'));
    });

    test('subscribe with conditions only delivers when matching', function (): void {
        Http::fake([
            'https://partner.example.com/hook' => Http::response(['ok' => true], 200),
        ]);

        EventManagerFacade::subscribe('order.placed', 'https://partner.example.com/hook')
            ->withFilter(['status' => 'paid'])
            ->save();

        // Fire with non-matching conditions
        EventManagerFacade::fire('order.placed', ['status' => 'pending']);

        Http::assertNothingSent();

        // Fire with matching conditions
        EventManagerFacade::fire('order.placed', ['status' => 'paid']);

        Http::assertSentCount(1);
    });

    test('subscribe with wildcard event matches sub-events', function (): void {
        Http::fake([
            'https://partner.example.com/hook' => Http::response(['ok' => true], 200),
        ]);

        EventManagerFacade::subscribe('order.*', 'https://partner.example.com/hook')
            ->save();

        EventManagerFacade::fire('order.placed', ['id' => 1]);
        EventManagerFacade::fire('order.shipped', ['id' => 2]);
        EventManagerFacade::fire('user.created', ['id' => 3]);

        Http::assertSentCount(2);
    });
});
