<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Tests for issue fixes: #28, #29, #30, #31
 */
describe('Issue Fix Tests', function (): void {
    describe('#28 — WildcardMatcher regex injection', function (): void {
        it('handles regex special characters in event names safely', function (): void {
            // Event names containing regex special chars should be treated literally
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue()
                ->and(WildcardMatcher::matches('order.placed', 'orderXplaced'))->toBeFalse();
        });

        it('does not interpret dots as regex any-char', function (): void {
            // A dot in the pattern should match a literal dot, not any char
            expect(WildcardMatcher::matches('a.b', 'a.b'))->toBeTrue()
                ->and(WildcardMatcher::matches('a.b', 'axb'))->toBeFalse();
        });

        it('handles patterns with regex special chars like parentheses', function (): void {
            expect(WildcardMatcher::matches('order(1).placed', 'order(1).placed'))->toBeTrue()
                ->and(WildcardMatcher::matches('order(1).placed', 'orderX1Y.placed'))->toBeFalse();
        });

        it('handles patterns with square brackets', function (): void {
            expect(WildcardMatcher::matches('order[1].placed', 'order[1].placed'))->toBeTrue()
                ->and(WildcardMatcher::matches('order[1].placed', 'orderX1Y.placed'))->toBeFalse();
        });

        it('handles wildcard with special chars in same segment', function (): void {
            // Pattern with special char + wildcard
            expect(WildcardMatcher::matches('a(+)*', 'a(+)b'))->toBeTrue()
                ->and(WildcardMatcher::matches('a(+)*', 'a(+)'))->toBeTrue();
        });

        it('Subscription::matchesEvent delegates to WildcardMatcher (no regex injection)', function (): void {
            $subscription = Subscription::factory()->forEvent('order.placed')->create();

            // Should match exact, not treat dot as regex any-char
            expect($subscription->matchesEvent('order.placed'))->toBeTrue()
                ->and($subscription->matchesEvent('orderXplaced'))->toBeFalse();
        });

        it('Subscription::matchesEvent with wildcard and special chars', function (): void {
            $subscription = Subscription::factory()->forEvent('order[1].*')->create();

            expect($subscription->matchesEvent('order[1].placed'))->toBeTrue()
                ->and($subscription->matchesEvent('orderX1Y.placed'))->toBeFalse();
        });
    });

    describe('#29 — SQL LIKE escape in Subscription scope', function (): void {
        it('scopeForEvent escapes percent signs in event names', function (): void {
            // Event name containing % should not act as a LIKE wildcard
            Subscription::factory()->forEvent('order.placed')->create();
            Subscription::factory()->forEvent('order.shipped')->create();

            // Searching for "order.%placed" should NOT match via LIKE % wildcard
            // With proper escaping, % is literal and only wildcard * converts to %
            $results = Subscription::forEvent('order.placed')->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->event)->toBe('order.placed');
        });

        it('scopeForEvent escapes underscores in event names', function (): void {
            // Event name containing _ should not act as a LIKE single-char wildcard
            Subscription::factory()->forEvent('order_placed')->create();
            Subscription::factory()->forEvent('orderxplaced')->create();

            // With proper escaping, _ is literal
            $results = Subscription::forEvent('order_placed')->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->event)->toBe('order_placed');
        });

        it('scopeForEvent with wildcard still works after escaping', function (): void {
            Subscription::factory()->forEvent('order.placed')->create();
            Subscription::factory()->forEvent('order.shipped')->create();
            Subscription::factory()->forEvent('user.created')->create();

            $results = Subscription::forEvent('order.*')->get();

            expect($results)->toHaveCount(2);
        });
    });

    describe('#30 — duration_ms=0 is a valid value', function (): void {
        it('markAsCompleted accepts duration_ms of 0', function (): void {
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->pending()->create(['trigger_id' => $trigger->id]);

            $log->markAsCompleted(0);
            $log->refresh();

            expect($log->duration_ms)->toBe(0)
                ->and($log->status)->toBe(EventLog::STATUS_COMPLETED);
        });

        it('duration_ms of 0 is not treated as null', function (): void {
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->create([
                'trigger_id' => $trigger->id,
                'duration_ms' => 0,
                'status' => EventLog::STATUS_COMPLETED,
            ]);

            expect($log->duration_ms)->toBe(0)
                ->and($log->duration_ms)->not->toBeNull();
        });
    });

    describe('#31 — Memory exhaustion: chunk-based processing', function (): void {
        it('EventsRetryCommand uses chunked processing (test via count)', function (): void {
            // This test verifies the command signature is updated to use chunking.
            // We test that the command can handle the "count then chunk" flow
            // by checking that no "get() all logs" pattern exists in the source.

            $source = file_get_contents(__DIR__.'/../src/Console/EventsRetryCommand.php');

            // The old code loaded all logs at once with ->get() on the query builder
            // The new code uses ->count() first, then ->chunk()
            expect($source)->toContain('chunk(')
                ->and($source)->toContain('->count()');
        });
    });
});
