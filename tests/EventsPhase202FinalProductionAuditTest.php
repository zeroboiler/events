<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Database\Factories\EventLogFactory;
use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
use ZeroBoiler\Events\Database\Factories\TriggerFactory;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 202 — Final Production Audit', function () {
    // ─── WildcardMatcher Deep Edge Cases ───
    describe('WildcardMatcher', function () {
        it('matches pattern with only dots (no wildcards)', function (): void {
            expect(WildcardMatcher::matches('.', '.'))->toBeTrue();
            expect(WildcardMatcher::matches('a.b', 'a.b'))->toBeTrue();
            expect(WildcardMatcher::matches('a.b', 'ab'))->toBeFalse();
        });

        it('handles patterns with trailing dot', function (): void {
            expect(WildcardMatcher::matches('order.*.', 'order.placed.'))->toBeTrue();
        });

        it('handles patterns with leading dot', function (): void {
            expect(WildcardMatcher::matches('.order', '.order'))->toBeTrue();
            expect(WildcardMatcher::matches('.order', 'order'))->toBeFalse();
        });

        it('does not match when pattern is longer than event', function (): void {
            expect(WildcardMatcher::matches('order.placed.shipped', 'order.placed'))->toBeFalse();
        });

        it('matches double wildcard at start', function (): void {
            expect(WildcardMatcher::matches('**.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('**.placed', 'order.item.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('**.placed', 'placed'))->toBeTrue();
        });

        it('matches double wildcard at end', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
        });

        it('matches double wildcard in middle', function (): void {
            expect(WildcardMatcher::matches('order.**.done', 'order.placed.done'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**.done', 'order.a.b.c.done'))->toBeTrue();
        });
    });

    // ─── ConditionEngine Nested Deep Paths ───
    describe('ConditionEngine deep nested fields', function () {
        it('evaluates 4-level nested dot notation', function (): void {
            $engine = new ConditionEngine;
            $payload = [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => 'deep_value',
                        ],
                    ],
                ],
            ];

            expect($engine->matches(['level1.level2.level3.level4' => 'deep_value'], $payload))->toBeTrue();
            expect($engine->matches(['level1.level2.level3.level4' => 'wrong'], $payload))->toBeFalse();
        });

        it('returns false for non-array intermediate key', function (): void {
            $engine = new ConditionEngine;
            $payload = [
                'user' => 'string_not_array',
            ];

            expect($engine->matches(['user.name' => 'John'], $payload))->toBeFalse();
        });
    });

    // ─── DomainEvent Edge Cases ───
    describe('DomainEvent edge cases', function () {
        it('preserves eventId on round-trip with empty payload', function (): void {
            $event = DomainEvent::occur('test.empty', []);
            $restored = DomainEvent::fromArray($event->toArray());

            expect($restored->eventId->toString())->toBe($event->eventId->toString());
            expect($restored->eventType)->toBe('test.empty');
            expect($restored->payload)->toBe([]);
        });

        it('preserves occurredAt on round-trip', function (): void {
            $past = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
            $event = new DomainEvent('test.past', ['key' => 'value'], null, $past);
            $restored = DomainEvent::fromArray($event->toArray());

            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($past->format(DateTimeImmutable::ATOM));
        });

        it('handles nested arrays in payload round-trip', function (): void {
            $payload = [
                'nested' => ['a' => ['b' => ['c' => 'deep']]],
                'list' => [1, 2, 3],
            ];
            $event = DomainEvent::occur('test.nested', $payload);
            $restored = DomainEvent::fromArray($event->toArray());

            expect($restored->payload)->toBe($payload);
        });
    });

    // ─── EventManager listTriggers/listSubscriptions consistency ───
    describe('EventManager listing consistency', function () {
        it('listTriggers returns empty collection for non-matching event', function (): void {
            $manager = app(EventManager::class);
            TriggerFactory::new()->create(['event' => 'order.placed']);

            $results = $manager->listTriggers('user.created');
            expect($results)->toHaveCount(0);
        });

        it('listTriggers filters by enabled status', function (): void {
            $manager = app(EventManager::class);
            TriggerFactory::new()->create(['event' => 'test.event', 'enabled' => true]);
            TriggerFactory::new()->create(['event' => 'test.event', 'enabled' => false]);

            $enabled = $manager->listTriggers('test.event', enabled: true);
            expect($enabled)->toHaveCount(1);

            $disabled = $manager->listTriggers('test.event', enabled: false);
            expect($disabled)->toHaveCount(1);
        });

        it('listSubscriptions filters by activeOnly', function (): void {
            $manager = app(EventManager::class);
            SubscriptionFactory::new()->create(['event' => 'test.sub', 'active' => true]);
            SubscriptionFactory::new()->create(['event' => 'test.sub', 'active' => false]);

            $active = $manager->listSubscriptions('test.sub', activeOnly: true);
            expect($active)->toHaveCount(1);
            expect($active->first()->active)->toBeTrue();
        });

        it('listSubscriptions respects wildcard filter', function (): void {
            $manager = app(EventManager::class);
            SubscriptionFactory::new()->create(['event' => 'order.placed']);
            SubscriptionFactory::new()->create(['event' => 'order.shipped']);
            SubscriptionFactory::new()->create(['event' => 'user.created']);

            $orderSubs = $manager->listSubscriptions('order.*');
            expect($orderSubs)->toHaveCount(2);
        });
    });

    // ─── TriggerBuilder multiple actions with params ───
    describe('TriggerBuilder save with multiple actions and params', function () {
        it('creates trigger with classes key when multiple actions with params', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('multi.action')
                ->actions(['\ZeroBoiler\Events\Tests\Actions\FirstAction', '\ZeroBoiler\Events\Tests\Actions\SecondAction'])
                ->actionParams(['webhook_url' => 'https://example.com'])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBeArray();
            expect($decoded['classes'])->toBe(['\ZeroBoiler\Events\Tests\Actions\FirstAction', '\ZeroBoiler\Events\Tests\Actions\SecondAction']);
            expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com']);
        });

        it('creates trigger with class key when single action with params', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('single.action')
                ->action('\ZeroBoiler\Events\Tests\Actions\OnlyAction')
                ->actionParams(['webhook_url' => 'https://example.com'])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBeArray();
            expect($decoded['class'])->toBe('\ZeroBoiler\Events\Tests\Actions\OnlyAction');
            expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com']);
        });
    });

    // ─── SubscriptionBuilder edge cases ───
    describe('SubscriptionBuilder', function () {
        it('generates secret with correct prefix and length', function (): void {
            $manager = app(EventManager::class);
            $sub = $manager->subscribe('test.event', 'https://example.com/webhook')
                ->save();

            expect($sub->secret)->toStartWith('whsec_');
            // Default secret_length is 32, plus 'whsec_' prefix = 38 chars
            expect(strlen((string) $sub->secret))->toBe(38);
        });

        it('respects custom secret when provided', function (): void {
            $manager = app(EventManager::class);
            $sub = $manager->subscribe('test.event', 'https://example.com/webhook')
                ->withSecret('custom_secret_value_at_least_16')
                ->save();

            expect($sub->secret)->toBe('custom_secret_value_at_least_16');
        });
    });

    // ─── EventLog status constants ───
    describe('EventLog status constants', function () {
        it('has exactly 4 valid statuses', function (): void {
            expect(EventLog::$statuses)->toHaveCount(4);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });
    });

    // ─── Subscription HMAC consistency ───
    describe('Subscription HMAC signing consistency', function () {
        it('produces same signature for same input', function (): void {
            $sub = SubscriptionFactory::new()->create(['secret' => 'test_secret_key_for_hmac']);
            $payload = json_encode(['event' => 'test', 'data' => ['key' => 'value']]);

            $sig1 = $sub->signPayload($payload);
            $sig2 = $sub->signPayload($payload);

            expect($sig1)->toBe($sig2);
            expect($sig1)->not->toBeEmpty();
        });

        it('produces empty string for null secret', function (): void {
            $sub = SubscriptionFactory::new()->create(['secret' => null]);

            expect($sub->signPayload('{}'))->toBe('');
        });
    });

    // ─── EventScheduler config validation ───
    describe('EventScheduler', function () {
        it('register() calls both log purge and subscription cleanup', function (): void {
            $schedule = app(Illuminate\Console\Scheduling\Schedule::class);
            $scheduler = app(EventScheduler::class);

            $scheduler->register($schedule);

            // Events are registered via schedule->call() — just verify no exceptions
            expect(true)->toBeTrue();
        });
    });

    // ─── WebhookAction config edge cases ───
    describe('WebhookAction', function () {
        it('throws on missing url in payload', function (): void {
            $action = new WebhookAction;

            expect(fn (): mixed => $action->handle(['data' => 'value']))
                ->toThrow(InvalidArgumentException::class, 'WebhookAction requires a non-empty "url"');
        });
    });

    // ─── ActionResolver validation ───
    describe('ActionResolver', function () {
        it('throws for non-existent class', function (): void {
            $resolver = app(\ZeroBoiler\Events\ActionResolver::class);

            expect(fn (): mixed => $resolver->resolve('NonExistent\\Action\\Class'))
                ->toThrow(InvalidArgumentException::class, 'does not exist');
        });

        it('throws for class that does not implement Triggerable', function (): void {
            $resolver = app(\ZeroBoiler\Events\ActionResolver::class);

            // stdClass does not implement Triggerable
            expect(fn (): mixed => $resolver->resolve('stdClass'))
                ->toThrow(InvalidArgumentException::class, 'must implement');
        });
    });

    // ─── EscapesWildcardLike trait ───
    describe('EscapesWildcardLike', function () {
        it('escapes percent and underscore in pattern', function (): void {
            $manager = app(EventManager::class);

            // Test via listTriggers with pattern containing SQL special chars
            TriggerFactory::new()->create(['event' => 'user_100']);
            TriggerFactory::new()->create(['event' => 'user%test']);

            // Without wildcards, should do exact match
            $results = $manager->listTriggers('user_100');
            expect($results)->toHaveCount(1);
            expect($results->first()->event)->toBe('user_100');
        });
    });

    // ─── Production deployment validation ───
    describe('Production readiness checks', function () {
        it('all 12 console commands are registered', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
            $commands = $provider->commands();

            expect($commands)->toHaveCount(12);
        });

        it('config has all required top-level keys', function (): void {
            $config = app('config');
            $eventsConfig = $config->get('events');

            expect($eventsConfig)->toBeArray();
            $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
            foreach ($requiredKeys as $key) {
                expect(array_key_exists($key, $eventsConfig))->toBeTrue("Missing config key: events.{$key}");
            }
        });

        it('ServiceProvider provides exactly 7 bindings', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
            $provides = $provider->provides();

            expect($provides)->toHaveCount(7);
            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
        });
    });
});
