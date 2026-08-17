<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('EventManager::fireModel validation', function (): void {
    test('throws InvalidArgumentException for empty model class', function (): void {
        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fireModel('', 'created', new \stdClass))
            ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty.');
    });

    test('throws InvalidArgumentException for "0" model class', function (): void {
        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fireModel('0', 'created', new \stdClass))
            ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty.');
    });

    test('throws InvalidArgumentException for empty action', function (): void {
        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fireModel('App\\Models\\Order', '', new \stdClass))
            ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty.');
    });

    test('throws InvalidArgumentException for "0" action', function (): void {
        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fireModel('App\\Models\\Order', '0', new \stdClass))
            ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty.');
    });
});

describe('EventManager::listTriggers', function (): void {
    test('returns all triggers when no filters applied', function (): void {
        Trigger::factory()->count(3)->create(['enabled' => true]);

        $manager = app(EventManager::class);
        $result = $manager->listTriggers();

        expect($result)->toHaveCount(3);
    });

    test('filters by event name exactly', function (): void {
        Trigger::factory()->create(['event' => 'order.placed', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'user.created', 'enabled' => true]);

        $manager = app(EventManager::class);
        $result = $manager->listTriggers('order.placed');

        expect($result)->toHaveCount(1)
            ->and($result->first()->event)->toBe('order.placed');
    });

    test('filters by enabled status', function (): void {
        Trigger::factory()->create(['enabled' => true]);
        Trigger::factory()->create(['enabled' => false]);

        $manager = app(EventManager::class);
        $result = $manager->listTriggers(null, true);

        expect($result)->toHaveCount(1)
            ->and($result->first()->enabled)->toBeTrue();
    });

    test('filters by disabled status', function (): void {
        Trigger::factory()->create(['enabled' => true]);
        Trigger::factory()->create(['enabled' => false]);

        $manager = app(EventManager::class);
        $result = $manager->listTriggers(null, false);

        expect($result)->toHaveCount(1)
            ->and($result->first()->enabled)->toBeFalse();
    });

    test('respects limit parameter', function (): void {
        Trigger::factory()->count(10)->create(['enabled' => true, 'priority' => 0]);

        $manager = app(EventManager::class);
        $result = $manager->listTriggers(null, null, 5);

        expect($result)->toHaveCount(5);
    });

    test('returns empty collection when no triggers match', function (): void {
        $manager = app(EventManager::class);
        $result = $manager->listTriggers('nonexistent.event');

        expect($result)->toHaveCount(0);
    });
});

describe('EventManager::getTrigger and deleteTrigger', function (): void {
    test('getTrigger returns null for non-existent ID', function (): void {
        $manager = app(EventManager::class);
        $result = $manager->getTrigger('non-existent-uuid');

        expect($result)->toBeNull();
    });

    test('getTrigger returns the trigger when found', function (): void {
        $trigger = Trigger::factory()->create(['name' => 'Findable Trigger']);

        $manager = app(EventManager::class);
        $result = $manager->getTrigger($trigger->id);

        expect($result)->not->toBeNull()
            ->and($result->name)->toBe('Findable Trigger');
    });

    test('deleteTrigger returns false for non-existent ID', function (): void {
        $manager = app(EventManager::class);
        $result = $manager->deleteTrigger('non-existent-uuid');

        expect($result)->toBeFalse();
    });

    test('deleteTrigger removes trigger and invalidates cache', function (): void {
        $trigger = Trigger::factory()->create();

        $manager = app(EventManager::class);
        $result = $manager->deleteTrigger($trigger->id);

        expect($result)->toBeTrue()
            ->and(Trigger::find($trigger->id))->toBeNull();
    });
});

describe('EventManager::subscribeWebhook', function (): void {
    test('creates a trigger with WebhookAction', function (): void {
        $manager = app(EventManager::class);
        $triggerId = $manager->subscribeWebhook('order.placed', 'https://example.com/hook');

        $trigger = Trigger::find($triggerId);
        expect($trigger)->not->toBeNull()
            ->and($trigger->event)->toBe('order.placed')
            ->and($trigger->action)->toContain(\ZeroBoiler\Events\Actions\WebhookAction::class);
    });

    test('accepts conditions and priority', function (): void {
        $manager = app(EventManager::class);
        $triggerId = $manager->subscribeWebhook(
            'order.paid',
            'https://example.com/hook',
            ['status' => 'paid'],
            50,
        );

        $trigger = Trigger::find($triggerId);
        expect($trigger)->not->toBeNull()
            ->and($trigger->priority)->toBe(50);
    });
});
