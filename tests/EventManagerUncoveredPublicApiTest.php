<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\Actions\NullAction;
use ZeroBoiler\Events\Facades\EventManager;

describe('EventManager uncovered public API', function () {
    it('container() returns the application container', function () {
        $container = EventManager::container();

        expect($container)->toBeInstanceOf(\Illuminate\Container\Container::class);
    });

    it('getSubscription() returns null for non-existent ID', function () {
        $result = EventManager::getSubscription('non-existent-uuid');

        expect($result)->toBeNull();
    });

    it('getSubscription() returns the subscription when found', function () {
        $sub = Subscription::factory()->create();

        $result = EventManager::getSubscription($sub->id);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($sub->id)
            ->and($result->url)->toBe($sub->url);
    });

    it('getSubscription() returns null for empty string ID', function () {
        $result = EventManager::getSubscription('');

        expect($result)->toBeNull();
    });

    it('subscribeWebhook() creates a trigger and returns its ID', function () {
        $triggerId = EventManager::subscribeWebhook('test.webhook', 'https://example.com/hook');

        expect($triggerId)->toBeString()
            ->and(strlen($triggerId))->toBeGreaterThan(0);

        // Verify a trigger was actually created
        $trigger = Trigger::find($triggerId);
        expect($trigger)->not->toBeNull()
            ->and($trigger->event)->toBe('test.webhook');
    });

    it('subscribeWebhook() creates a subscription record', function () {
        EventManager::subscribeWebhook('sub.webhook.test', 'https://example.com/webhook');

        $subs = Subscription::where('event', 'sub.webhook.test')->get();
        expect($subs->count())->toBeGreaterThanOrEqual(1);
    });

    it('subscribeWebhook() passes conditions to the trigger', function () {
        $conditions = ['status' => 'active'];
        $triggerId = EventManager::subscribeWebhook(
            'cond.webhook.test',
            'https://example.com/hook',
            $conditions,
            priority: 5,
        );

        $trigger = Trigger::find($triggerId);
        expect($trigger)->not->toBeNull()
            ->and($trigger->conditions)->toBe($conditions)
            ->and($trigger->priority)->toBe(5);
    });

    it('listSubscriptions() returns subscriptions for an event', function () {
        Subscription::factory()->create(['event' => 'list.test.event']);
        Subscription::factory()->create(['event' => 'list.test.event']);

        $result = EventManager::listSubscriptions('list.test.event');

        expect($result->count())->toBeGreaterThanOrEqual(2);
    });

    it('listSubscriptions() filters with activeOnly', function () {
        Subscription::factory()->create(['event' => 'active.filter.test', 'active' => true]);
        Subscription::factory()->create(['event' => 'active.filter.test', 'active' => false]);

        $active = EventManager::listSubscriptions('active.filter.test', activeOnly: true);
        $all = EventManager::listSubscriptions('active.filter.test', activeOnly: false);

        expect($active->count())->toBeLessThanOrEqual($all->count())
            ->and($active->every(fn (Subscription $s): bool => $s->active === true))->toBeTrue();
    });

    it('listSubscriptions() supports wildcard filtering', function () {
        Subscription::factory()->create(['event' => 'wild.sub.order.created']);
        Subscription::factory()->create(['event' => 'wild.sub.order.shipped']);
        Subscription::factory()->create(['event' => 'unrelated.event']);

        $result = EventManager::listSubscriptions('wild.sub.order.*');

        expect($result->count())->toBeGreaterThanOrEqual(2);

        // All returned events should match the pattern
        foreach ($result as $sub) {
            expect($sub->event)->toMatch('/^wild\.sub\.order\./');
        }
    });

    it('getStalePendingLogs() returns logs older than threshold', function () {
        $trigger = Trigger::factory()->create();

        // Create a pending log with a past created_at
        $log = \ZeroBoiler\Events\Models\EventLog::factory()
            ->forTrigger($trigger->id)
            ->pending()
            ->create();

        // Manually backdate the log to make it "stale"
        $log->update(['created_at' => Carbon::now()->subHours(2)]);

        $stale = EventManager::getStalePendingLogs(Carbon::now()->subHour());

        expect($stale->count())->toBeGreaterThanOrEqual(1);
    });

    it('deactivateExceededSubscriptions() deactivates subscriptions over threshold', function () {
        // Create a subscription with high failure count
        $sub = Subscription::factory()->create([
            'active' => true,
            'failure_count' => 15, // default max is 10
        ]);

        $count = EventManager::deactivateExceededSubscriptions();

        expect($count)->toBeGreaterThanOrEqual(1);

        $sub->refresh();
        expect($sub->active)->toBeFalse();
    });

    it('deactivateExceededSubscriptions() does not deactivate subscriptions under threshold', function () {
        $sub = Subscription::factory()->create([
            'active' => true,
            'failure_count' => 3, // below default max of 10
        ]);

        EventManager::deactivateExceededSubscriptions();

        $sub->refresh();
        expect($sub->active)->toBeTrue();
    });

    it('register() is an alias for on()', function () {
        $builder1 = EventManager::on('alias.test.event');
        $builder2 = EventManager::register('alias.test.event');

        expect($builder1)->toBeInstanceOf(\ZeroBoiler\Events\TriggerBuilder::class)
            ->and($builder2)->toBeInstanceOf(\ZeroBoiler\Events\TriggerBuilder::class);
    });

    it('listTriggers() with null filters returns all triggers', function () {
        Trigger::factory()->count(3)->create();

        $result = EventManager::listTriggers();

        expect($result->count())->toBeGreaterThanOrEqual(3);
    });

    it('listTriggers() filters by enabled status', function () {
        Trigger::factory()->enabled()->create();
        Trigger::factory()->disabled()->create();

        $enabled = EventManager::listTriggers(enabled: true);
        $disabled = EventManager::listTriggers(enabled: false);

        expect($enabled->count())->toBeGreaterThanOrEqual(1)
            ->and($disabled->count())->toBeGreaterThanOrEqual(1);
    });

    it('deleteTrigger() returns false for non-existent ID', function () {
        $result = EventManager::deleteTrigger('non-existent-uuid');

        expect($result)->toBeFalse();
    });

    it('deleteTrigger() returns true and deletes the trigger', function () {
        $trigger = Trigger::factory()->create();

        $result = EventManager::deleteTrigger($trigger->id);

        expect($result)->toBeTrue();
        expect(Trigger::find($trigger->id))->toBeNull();
    });

    it('enable() and disable() return false for empty string', function () {
        expect(EventManager::enable(''))->toBeFalse();
        expect(EventManager::disable(''))->toBeFalse();
        expect(EventManager::enable('0'))->toBeFalse();
        expect(EventManager::disable('0'))->toBeFalse();
    });

    it('purgeLogs() deletes old completed logs', function () {
        $trigger = Trigger::factory()->create();

        $oldLog = \ZeroBoiler\Events\Models\EventLog::factory()
            ->forTrigger($trigger->id)
            ->completed()
            ->create();
        $oldLog->update(['created_at' => Carbon::now()->subDays(60)]);

        $deleted = EventManager::purgeLogs(Carbon::now()->subDays(30));

        expect($deleted)->toBeGreaterThanOrEqual(1);
    });
});
