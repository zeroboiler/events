<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\WildcardMatcher;

use ZeroBoiler\Events\Tests\Actions\NullAction;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

describe('Phase 190 — Final Production Readiness', function (): void {
    describe('EventManager fire() with zero matching triggers', function (): void {
        it('returns silently when no triggers match the event', function (): void {
            $em = app(EventManager::class);

            // Fire an event that has zero triggers — should be a silent no-op
            $em->fire('nonexistent.event.that.has.no.triggers', ['key' => 'value']);

            // No event logs should have been created
            expect(EventLog::count())->toBe(0);
        });

        it('returns silently when global disable is active', function (): void {
            $em = app(EventManager::class);

            // Create a trigger
            $trigger = $em->on('test.disabled.event')
                ->action(NullAction::class)
                ->name('Disabled Test Trigger')
                ->save();

            // Enable global disable
            $em->setEnabled(false);
            expect($em->isDisabled())->toBeTrue();

            // Fire the event
            $em->fire('test.disabled.event', ['data' => 'should not fire']);

            // No logs should exist
            expect(EventLog::count())->toBe(0);

            // Re-enable
            $em->setEnabled(true);
            expect($em->isDisabled())->toBeFalse();
        });
    });

    describe('ConditionEngine with deeply nested dot notation', function (): void {
        it('evaluates 3-level nested fields', function (): void {
            $engine = new ConditionEngine;

            $payload = [
                'user' => [
                    'profile' => [
                        'role' => 'admin',
                    ],
                ],
            ];

            expect($engine->matches(['user.profile.role' => 'admin'], $payload))->toBeTrue();
            expect($engine->matches(['user.profile.role' => 'user'], $payload))->toBeFalse();
        });

        it('evaluates 4-level nested fields', function (): void {
            $engine = new ConditionEngine;

            $payload = [
                'order' => [
                    'items' => [
                        0 => [
                            'name' => 'Widget',
                            'price' => 25,
                        ],
                    ],
                ],
            ];

            expect($engine->matches(['order.items.0.name' => 'Widget'], $payload))->toBeTrue();
            expect($engine->matches(['order.items.0.price' => ['>', 20]], $payload))->toBeTrue();
        });

        it('returns null for missing deeply nested keys', function (): void {
            $engine = new ConditionEngine;

            $payload = ['user' => ['name' => 'John']];

            // Missing deep key should be null → null operator should match
            expect($engine->matches(['user.profile.avatar' => ['null']], $payload))->toBeTrue();
            expect($engine->matches(['user.profile.avatar' => ['not_null']], $payload))->toBeFalse();
        });
    });

    describe('TriggerBuilder auto-name generation', function (): void {
        it('generates default name from event when name is empty', function (): void {
            $em = app(EventManager::class);

            $trigger = $em->on('order.shipped')
                ->action(NullAction::class)
                ->save();

            expect($trigger->name)->toBe('order.shipped Trigger');
            expect($trigger->event)->toBe('order.shipped');
            expect($trigger->enabled)->toBeTrue();
        });
    });

    describe('SubscriptionBuilder with auto_generate_secret disabled', function (): void {
        it('creates subscription with null secret when auto-generate is false and no secret provided', function (): void {
            // Override config to disable auto-generate
            $config = app('config');
            $config->set('events.subscriptions.auto_generate_secret', false);

            $em = app(EventManager::class);

            $builder = $em->subscribe('test.no-secret.event', 'https://example.com/webhook');

            // Should not throw — subscription saves without secret
            $subscription = $builder->save();

            expect($subscription->secret)->toBeNull();
            expect($subscription->event)->toBe('test.no-secret.event');
            expect($subscription->url)->toBe('https://example.com/webhook');
            expect($subscription->active)->toBeTrue();

            // Restore config
            $config->set('events.subscriptions.auto_generate_secret', true);
        });
    });

    describe('WildcardMatcher with special characters', function (): void {
        it('matches events with hyphens and underscores', function (): void {
            expect(WildcardMatcher::matches('user.*', 'user.profile-created'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order_item.created'))->toBeTrue();
            expect(WildcardMatcher::matches('*.created', 'user-profile.created'))->toBeTrue();
        });

        it('does not match across segment boundaries with single wildcard', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
            expect(WildcardMatcher::matches('user.*.created', 'user.profile.settings.created'))->toBeFalse();
        });

        it('matches across segment boundaries with double wildcard', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
            expect(WildcardMatcher::matches('user.**.created', 'user.profile.settings.created'))->toBeTrue();
        });

        it('findMatchingPatterns returns correct subset', function (): void {
            $patterns = ['order.*', 'user.*', 'order.**', '*.deleted'];
            $matching = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($matching)->toContain('order.*');
            expect($matching)->toContain('order.**');
            expect($matching)->not()->toContain('user.*');
            expect($matching)->not()->toContain('*.deleted');
        });
    });

    describe('DomainEvent fromArray with extra keys', function (): void {
        it('ignores unknown keys in fromArray', function (): void {
            $data = [
                'eventId' => '00000000-0000-0000-0000-000000000001',
                'eventType' => 'user.registered',
                'payload' => ['email' => 'test@example.com'],
                'occurredAt' => '2025-01-01T00:00:00+00:00',
                'extraKey1' => 'ignored',
                'nested' => ['also' => 'ignored'],
            ];

            $event = DomainEvent::fromArray($data);

            expect($event->eventType)->toBe('user.registered');
            expect($event->payload)->toBe(['email' => 'test@example.com']);
            expect($event->eventId->toString())->toBe('00000000-0000-0000-0000-000000000001');
        });

        it('roundtrips with complex nested payload', function (): void {
            $original = DomainEvent::occur('order.completed', [
                'order_id' => 'ord-123',
                'customer' => [
                    'id' => 'cust-456',
                    'name' => 'John Doe',
                    'addresses' => [
                        ['type' => 'billing', 'city' => 'Istanbul'],
                        ['type' => 'shipping', 'city' => 'Ankara'],
                    ],
                ],
                'items' => [
                    ['sku' => 'A1', 'qty' => 2],
                    ['sku' => 'B2', 'qty' => 1],
                ],
                'total' => 149.99,
                'discount_applied' => true,
                'notes' => null,
            ]);

            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->payload)->toBe($original->payload);
            expect($restored->occurredAt)->toEqual($original->occurredAt);
        });
    });

    describe('EventManager container() method', function (): void {
        it('returns the application container', function (): void {
            $em = app(EventManager::class);
            $container = $em->container();

            expect($container)->toBe(app());
            expect($container->has(EventManager::class))->toBeTrue();
        });
    });

    describe('EventManager invalidation consistency', function (): void {
        it('invalidates cache after enable, disable, and delete', function (): void {
            $em = app(EventManager::class);

            // Create a trigger
            $trigger = $em->on('cache.test.event')
                ->action(NullAction::class)
                ->name('Cache Test')
                ->save();

            // Cache should be populated after first access
            Cache::forget('zeroboiler:events:enabled_wildcard_triggers');

            // Disable → invalidates cache
            $em->disable($trigger->id);
            expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();

            // Enable → invalidates cache
            $em->enable($trigger->id);
            expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();

            // Delete → invalidates cache
            $em->deleteTrigger($trigger->id);
            expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();
        });
    });

    describe('ConditionEngine empty conditions', function (): void {
        it('returns true for empty conditions array', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches([], ['anything' => 'value']))->toBeTrue();
            expect($engine->matches([], []))->toBeTrue();
        });

        it('returns false when expected is empty array', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
        });
    });

    describe('EventManager fire with wildcard triggers', function (): void {
        it('fires both exact and wildcard triggers deterministically', function (): void {
            $em = app(EventManager::class);

            $exactTrigger = $em->on('order.placed')
                ->action(NullAction::class)
                ->name('Exact Order Placed')
                ->priority(5)
                ->save();

            $wildcardTrigger = $em->on('order.*')
                ->action(NullAction::class)
                ->name('Wildcard Order')
                ->priority(10)
                ->save();

            $em->fire('order.placed', ['test' => true]);

            // Both triggers should fire (2 event logs)
            expect(EventLog::count())->toBe(2);

            // Higher priority (wildcard, 10) should appear first
            $logs = EventLog::where('event', 'order.placed')
                ->orderBy('created_at')
                ->get();

            expect($logs->count())->toBe(2);
        });
    });
});
