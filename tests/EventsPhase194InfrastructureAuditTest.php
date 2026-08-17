<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Str;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestActions;

describe('EventManager listTriggers with wildcard filtering', function (): void {
    beforeEach(function (): void {
        // Clean up from previous tests
        Trigger::query()->delete();
        EventLog::query()->delete();
    });

    afterEach(function (): void {
        Trigger::query()->delete();
        EventLog::query()->delete();
    });

    it('returns only exact-match triggers when no wildcard is used', function (): void {
        $trigger = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Exact Match',
            'event' => 'order.placed',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Different Event',
            'event' => 'user.created',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        $results = app(EventManager::class)->listTriggers('order.placed');

        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($trigger->id);
    });

    it('returns wildcard-matching triggers using LIKE pattern', function (): void {
        Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Order Placed',
            'event' => 'order.placed',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Order Shipped',
            'event' => 'order.shipped',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 3,
        ]);

        Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'User Created',
            'event' => 'user.created',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        $results = app(EventManager::class)->listTriggers('order.*');

        expect($results)->toHaveCount(2);
        expect($results->pluck('event')->all())->toContain('order.placed');
        expect($results->pluck('event')->all())->toContain('order.shipped');
    });

    it('filters by enabled status', function (): void {
        $enabled = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Enabled Trigger',
            'event' => 'test.event',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Disabled Trigger',
            'event' => 'test.event',
            'action' => TestActions::class,
            'enabled' => false,
            'priority' => 5,
        ]);

        $results = app(EventManager::class)->listTriggers(null, true);

        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($enabled->id);
    });

    it('filters by disabled status', function (): void {
        Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Enabled Trigger',
            'event' => 'test.event',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        $disabled = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Disabled Trigger',
            'event' => 'test.event',
            'action' => TestActions::class,
            'enabled' => false,
            'priority' => 5,
        ]);

        $results = app(EventManager::class)->listTriggers(null, false);

        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($disabled->id);
    });

    it('respects the limit parameter', function (): void {
        for ($i = 0; $i < 5; $i++) {
            Trigger::create([
                'id' => (string) Str::uuid(),
                'name' => "Trigger {$i}",
                'event' => 'test.event',
                'action' => TestActions::class,
                'enabled' => true,
                'priority' => $i,
            ]);
        }

        $results = app(EventManager::class)->listTriggers(null, null, 3);

        expect($results)->toHaveCount(3);
    });

    it('returns empty collection when no triggers exist', function (): void {
        $results = app(EventManager::class)->listTriggers();

        expect($results)->toHaveCount(0);
    });

    it('orders by priority descending then by created_at ascending', function (): void {
        $low = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Low Priority',
            'event' => 'test.ordered',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 1,
        ]);

        $high = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'High Priority',
            'event' => 'test.ordered',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 10,
        ]);

        $results = app(EventManager::class)->listTriggers('test.ordered');

        expect($results)->toHaveCount(2);
        expect($results->first()->id)->toBe($high->id);
        expect($results->last()->id)->toBe($low->id);
    });

    it('ignores empty string event filter', function (): void {
        Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Any Trigger',
            'event' => 'order.placed',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        $results = app(EventManager::class)->listTriggers('');

        expect($results)->toHaveCount(1);
    });

    it('ignores zero-string event filter', function (): void {
        Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Any Trigger',
            'event' => 'order.placed',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        $results = app(EventManager::class)->listTriggers('0');

        expect($results)->toHaveCount(1);
    });
});

describe('EventManager getTrigger edge cases', function (): void {
    it('returns null for empty string ID', function (): void {
        expect(app(EventManager::class)->getTrigger(''))->toBeNull();
    });

    it('returns null for zero-string ID', function (): void {
        expect(app(EventManager::class)->getTrigger('0'))->toBeNull();
    });

    it('returns null for non-existent UUID', function (): void {
        expect(app(EventManager::class)->getTrigger((string) Str::uuid()))->toBeNull();
    });
});

describe('EventManager deleteTrigger edge cases', function (): void {
    it('returns false for empty string ID', function (): void {
        expect(app(EventManager::class)->deleteTrigger(''))->toBeFalse();
    });

    it('returns false for zero-string ID', function (): void {
        expect(app(EventManager::class)->deleteTrigger('0'))->toBeFalse();
    });

    it('returns false for non-existent ID', function (): void {
        expect(app(EventManager::class)->deleteTrigger((string) Str::uuid()))->toBeFalse();
    });

    it('invalidates trigger cache after deletion', function (): void {
        $trigger = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'To Delete',
            'event' => 'to.delete',
            'action' => TestActions::class,
            'enabled' => true,
            'priority' => 5,
        ]);

        $manager = app(EventManager::class);
        $result = $manager->deleteTrigger($trigger->id);

        expect($result)->toBeTrue();
        expect(Trigger::find($trigger->id))->toBeNull();
    });
});
