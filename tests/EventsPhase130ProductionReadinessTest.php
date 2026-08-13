<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 130 Production Readiness', function (): void {

    // ─────────────────────────────────────────────
    // EventManager::registerScheduler facade path
    // ─────────────────────────────────────────────

    describe('EventManager::registerScheduler', function (): void {
        it('delegates to EventScheduler::register() without throwing', function (): void {
            $manager = app(EventManager::class);

            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            expect(fn () => $manager->registerScheduler($schedule))->not->toThrow(\Throwable::class);
        });
    });

    // ─────────────────────────────────────────────
    // ManagesSubscriptions — subscribeWebhook
    // ─────────────────────────────────────────────

    describe('ManagesSubscriptions::subscribeWebhook', function (): void {
        it('creates a trigger but not a Subscription record', function (): void {
            $manager = app(EventManager::class);

            $beforeSubs = Subscription::count();
            $beforeTriggers = Trigger::count();

            $triggerId = $manager->subscribeWebhook(
                'test.subscribe.webhook',
                'https://example.com/webhook',
                ['status' => 'active'],
                5,
            );

            // A trigger should have been created
            expect(Trigger::count())->toBe($beforeTriggers + 1);
            expect($triggerId)->toBeString();
            expect($triggerId)->not->toBeEmpty();

            // No subscription should have been created
            expect(Subscription::count())->toBe($beforeSubs);

            // The trigger should reference the correct event
            $trigger = Trigger::find($triggerId);
            expect($trigger)->not->toBeNull();
            expect($trigger->event)->toBe('test.subscribe.webhook');
        });
    });

    // ─────────────────────────────────────────────
    // Subscription — signPayload edge cases
    // ─────────────────────────────────────────────

    describe('Subscription::signPayload', function (): void {
        it('returns empty string when secret is null', function (): void {
            $sub = Subscription::factory()->create(['secret' => null]);

            expect($sub->signPayload('{"test":"data"}'))->toBe('');
        });

        it('returns empty string when secret is empty string', function (): void {
            $sub = Subscription::factory()->create(['secret' => '']);

            expect($sub->signPayload('{"test":"data"}'))->toBe('');
        });

        it('returns valid HMAC signature when secret is set', function (): void {
            $sub = Subscription::factory()->create(['secret' => 'whsec_test_secret']);

            $signature = $sub->signPayload('{"test":"data"}');

            expect($signature)->not->toBe('');
            expect($signature)->not->toBe('0');

            // Verify the signature matches expected HMAC
            $expected = hash_hmac('sha256', '{"test":"data"}', 'whsec_test_secret');
            expect($signature)->toBe($expected);
        });

        it('uses configured signature algorithm', function (): void {
            Config::set('events.subscriptions.signature_algorithm', 'sha512');
            $sub = Subscription::factory()->create(['secret' => 'whsec_test_algo']);

            $signature = $sub->signPayload('test-payload');

            $expected = hash_hmac('sha512', 'test-payload', 'whsec_test_algo');
            expect($signature)->toBe($expected);

            // Reset
            Config::set('events.subscriptions.signature_algorithm', 'sha256');
        });
    });

    // ─────────────────────────────────────────────
    // ManagesSubscriptions — listSubscriptions ordering
    // ─────────────────────────────────────────────

    describe('ManagesSubscriptions::listSubscriptions', function (): void {
        it('returns subscriptions ordered by priority desc then created_at asc', function (): void {
            Subscription::factory()->create(['event' => 'test.list.order', 'priority' => 10]);
            Subscription::factory()->create(['event' => 'test.list.order', 'priority' => 50]);
            Subscription::factory()->create(['event' => 'test.list.order', 'priority' => 30]);

            $manager = app(EventManager::class);
            $subs = $manager->listSubscriptions('test.list.order');

            expect($subs->count())->toBe(3);
            expect($subs[0]->priority)->toBe(50);
            expect($subs[1]->priority)->toBe(30);
            expect($subs[2]->priority)->toBe(10);
        });

        it('filters by event with wildcard pattern', function (): void {
            Subscription::factory()->create(['event' => 'order.placed']);
            Subscription::factory()->create(['event' => 'order.shipped']);
            Subscription::factory()->create(['event' => 'user.created']);

            $manager = app(EventManager::class);
            $subs = $manager->listSubscriptions('order.*');

            expect($subs->count())->toBe(2);
            foreach ($subs as $sub) {
                expect(str_starts_with($sub->event, 'order.'))->toBeTrue();
            }
        });

        it('returns empty collection when no subscriptions match', function (): void {
            $manager = app(EventManager::class);
            $subs = $manager->listSubscriptions('non.existent.event');

            expect($subs)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
            expect($subs->count())->toBe(0);
        });
    });

    // ─────────────────────────────────────────────
    // EscapesWildcardLike trait (tested via Subscription model)
    // ─────────────────────────────────────────────

    describe('EscapesWildcardLike via Subscription scopeForEvent', function (): void {
        it('queries exact match for non-wildcard event', function (): void {
            Subscription::factory()->create(['event' => 'exact.match.event']);
            Subscription::factory()->create(['event' => 'other.event']);

            $subs = Subscription::forEvent('exact.match.event')->get();

            expect($subs->count())->toBe(1);
            expect($subs->first()->event)->toBe('exact.match.event');
        });

        it('queries wildcard match for pattern with asterisk', function (): void {
            Subscription::factory()->create(['event' => 'prefix.wildcard']);
            Subscription::factory()->create(['event' => 'prefix.other']);
            Subscription::factory()->create(['event' => 'nomatch.event']);

            $subs = Subscription::forEvent('prefix.*')->get();

            expect($subs->count())->toBe(2);
        });
    });

    // ─────────────────────────────────────────────
    // DomainEvent — roundtrip identity
    // ─────────────────────────────────────────────

    describe('DomainEvent roundtrip identity', function (): void {
        it('preserves eventId and occurredAt through toArray/fromArray roundtrip', function (): void {
            $original = DomainEvent::occur('test.roundtrip', ['key' => 'value']);
            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
            expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
        });
    });

    // ─────────────────────────────────────────────
    // DispatchTriggerJob — config-driven backoff
    // ─────────────────────────────────────────────

    describe('DispatchTriggerJob config-driven backoff', function (): void {
        it('uses comma-separated backoff from config', function (): void {
            Config::set('events.retry.backoff', '30,60,120');

            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                triggerId: (string) \Illuminate\Support\Str::uuid(),
                event: 'test.event',
                payload: ['key' => 'value'],
            );

            expect($job->backoff)->toBe([30, 60, 120]);

            // Reset
            Config::set('events.retry.backoff', '60,300,900');
        });

        it('uses array backoff from config', function (): void {
            Config::set('events.retry.backoff', [10, 20, 30]);

            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                triggerId: (string) \Illuminate\Support\Str::uuid(),
                event: 'test.event',
                payload: ['key' => 'value'],
            );

            expect($job->backoff)->toBe([10, 20, 30]);

            // Reset
            Config::set('events.retry.backoff', '60,300,900');
        });
    });

    // ─────────────────────────────────────────────
    // ServiceProvider — provides() completeness
    // ─────────────────────────────────────────────

    describe('ServiceProvider provides() completeness', function (): void {
        it('includes all registered services in provides()', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);

            $provides = $provider->provides();

            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ConditionEngine::class);
            expect($provides)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
            expect($provides)->toContain(\ZeroBoiler\Events\TriggerBuilder::class);
            expect($provides)->toContain(\ZeroBoiler\Events\SubscriptionBuilder::class);
            expect($provides)->toContain(\ZeroBoiler\Events\EventScheduler::class);
        });
    });

    // ─────────────────────────────────────────────
    // Subscription — matchesEvent edge cases
    // ─────────────────────────────────────────────

    describe('Subscription::matchesEvent', function (): void {
        it('returns true for exact match', function (): void {
            $sub = Subscription::factory()->create(['event' => 'order.placed']);

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.shipped'))->toBeFalse();
        });

        it('delegates to WildcardMatcher for wildcard patterns', function (): void {
            $sub = Subscription::factory()->create(['event' => 'order.*']);

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.shipped'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
        });

        it('delegates to WildcardMatcher for cross-segment patterns', function (): void {
            $sub = Subscription::factory()->create(['event' => 'order.**']);

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
            expect($sub->matchesEvent('order.a.b.c'))->toBeTrue();
        });
    });

    // ─────────────────────────────────────────────
    // WildcardMatcher — findMatchingPatterns
    // ─────────────────────────────────────────────

    describe('WildcardMatcher::findMatchingPatterns', function (): void {
        it('returns matching patterns from a list', function (): void {
            $patterns = ['order.*', 'user.*', 'order.placed', '*.created'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toContain('order.*');
            expect($result)->toContain('order.placed');
            expect($result)->not->toContain('user.*');
            expect($result)->not->toContain('*.created');
        });

        it('returns empty array when no patterns match', function (): void {
            $result = WildcardMatcher::findMatchingPatterns(['order.*'], 'user.created');

            expect($result)->toBe([]);
        });
    });

    // ─────────────────────────────────────────────
    // ManagesHistory — getStats with empty DB
    // ─────────────────────────────────────────────

    describe('ManagesHistory::getStats with empty database', function (): void {
        it('returns zero counts and null rates', function (): void {
            $manager = app(EventManager::class);
            $stats = $manager->getStats();

            expect($stats['total_logs'])->toBe(0);
            expect($stats['total_triggers'])->toBeInt();
            expect($stats['completed'])->toBe(0);
            expect($stats['failed'])->toBe(0);
            expect($stats['success_rate'])->toBeNull();
            expect($stats['failure_rate'])->toBeNull();
            expect($stats['avg_duration_ms'])->toBeNull();
            expect($stats['top_events'])->toBeArray();
            expect($stats['top_failed_events'])->toBeArray();
        });
    });
});
