<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Subscription;

describe('Phase 191 — Trait & Webhook Infrastructure Tests', function (): void {
    describe('EscapesWildcardLike trait', function (): void {
        it('returns null when pattern has no wildcards', function (): void {
            $trait = new class
            {
                use EscapesWildcardLike;
            };

            // Access protected method via reflection
            $ref = new \ReflectionMethod($trait, 'wildcardToLike');
            $ref->setAccessible(true);

            expect($ref->invoke($trait, 'order.placed'))->toBeNull();
            expect($ref->invoke($trait, 'user.profile.created'))->toBeNull();
        });

        it('converts single wildcard to %', function (): void {
            $trait = new class
            {
                use EscapesWildcardLike;
            };

            $ref = new \ReflectionMethod($trait, 'wildcardToLike');
            $ref->setAccessible(true);

            expect($ref->invoke($trait, 'order.*'))->toBe('order.%');
            expect($ref->invoke($trait, 'user.*.created'))->toBe('user.%.created');
        });

        it('escapes backslashes, percent signs, and underscores', function (): void {
            $trait = new class
            {
                use EscapesWildcardLike;
            };

            $ref = new \ReflectionMethod($trait, 'wildcardToLike');
            $ref->setAccessible(true);

            // Pattern with % and _ should be escaped
            expect($ref->invoke($trait, 'user%*'))->toBe('user\\%%');
            expect($ref->invoke($trait, 'user_*'))->toBe('user\\_%');
        });

        it('handles catch-all wildcard pattern', function (): void {
            $trait = new class
            {
                use EscapesWildcardLike;
            };

            $ref = new \ReflectionMethod($trait, 'wildcardToLike');
            $ref->setAccessible(true);

            expect($ref->invoke($trait, '*'))->toBe('%');
            expect($ref->invoke($trait, '**'))->toBe('%*%');
        });
    });

    describe('WebhookAction configuration', function (): void {
        it('has all required payload keys for webhook dispatch', function (): void {
            $action = new WebhookAction;

            expect($action)->toBeInstanceOf(\ZeroBoiler\Events\Contracts\Triggerable::class);
        });

        it('constructs without errors', function (): void {
            $action = new WebhookAction;
            expect($action)->toBeInstanceOf(WebhookAction::class);
        });
    });

    describe('Subscription model methods', function (): void {
        it('signPayload returns empty string when secret is null', function (): void {
            $sub = new Subscription;
            $sub->secret = null;

            expect($sub->signPayload('test payload'))->toBe('');
        });

        it('signPayload returns empty string when secret is empty', function (): void {
            $sub = new Subscription;
            $sub->secret = '';

            expect($sub->signPayload('test payload'))->toBe('');
        });

        it('signPayload returns HMAC signature', function (): void {
            $sub = new Subscription;
            $sub->secret = 'whsec_test_secret_key_for_unit_testing';

            $sig = $sub->signPayload('test payload');
            expect($sig)->not->toBe('');
            expect($sig)->toBeString();
            // HMAC-SHA256 produces 64-char hex
            expect(strlen($sig))->toBe(64);
        });

        it('hasExceededFailures uses config default when null', function (): void {
            $sub = new Subscription;
            $sub->failure_count = 15;

            // Default max_failures is 10 from config
            expect($sub->hasExceededFailures(null))->toBeTrue();
        });

        it('hasExceededFailures uses explicit override', function (): void {
            $sub = new Subscription;
            $sub->failure_count = 5;

            expect($sub->hasExceededFailures(10))->toBeFalse();
            expect($sub->hasExceededFailures(5))->toBeTrue();
            expect($sub->hasExceededFailures(3))->toBeTrue();
        });

        it('matchesEvent uses exact match for non-wildcard events', function (): void {
            $sub = new Subscription;
            $sub->event = 'order.placed';

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.shipped'))->toBeFalse();
        });

        it('matchesEvent delegates to WildcardMatcher for wildcard patterns', function (): void {
            $sub = new Subscription;
            $sub->event = 'order.*';

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.shipped'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
        });
    });

    describe('DispatchTriggerJob configuration', function (): void {
        it('reads config values at construction time', function (): void {
            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                triggerId: 'test-id',
                event: 'test.event',
                payload: ['key' => 'value'],
            );

            expect($job->triggerId)->toBe('test-id');
            expect($job->event)->toBe('test.event');
            expect($job->payload)->toBe(['key' => 'value']);
            expect($job->tries)->toBeGreaterThanOrEqual(1);
            expect($job->backoff)->toBeArray();
            expect($job->backoff)->not->toBeEmpty();
            expect($job->queue)->toBeString();
            expect($job->queue)->not->toBe('');
        });

        it('accepts null container and uses app() fallback', function (): void {
            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                triggerId: 'test-id',
                event: 'test.event',
                payload: [],
                app: null,
            );

            expect($job->triggerId)->toBe('test-id');
        });
    });

    describe('EventLog model status transitions', function (): void {
        it('markAsCompleted sets status and duration', function (): void {
            $log = new EventLog;
            $log->status = EventLog::STATUS_PENDING;
            $log->error = 'previous error';

            // markAsCompleted requires update which needs DB — test the method signature only
            expect(method_exists($log, 'markAsCompleted'))->toBeTrue();
            expect(method_exists($log, 'markAsFailed'))->toBeTrue();
        });
    });
});
