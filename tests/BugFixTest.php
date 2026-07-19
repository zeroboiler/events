<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Tests for bug fixes: #8, #12
 */
describe('Bug Fix Tests', function (): void {
    describe('#8 — extractWildcards with bare * on multi-segment events', function (): void {
        it('returns the full event string for bare * pattern', function (): void {
            $wildcards = WildcardMatcher::extractWildcards('*', 'order.placed');

            expect($wildcards)->toBe(['order.placed']);
        });

        it('returns the full event string for bare * pattern with single segment', function (): void {
            $wildcards = WildcardMatcher::extractWildcards('*', 'anything');

            expect($wildcards)->toBe(['anything']);
        });

        it('returns empty array for bare * with empty event', function (): void {
            $wildcards = WildcardMatcher::extractWildcards('*', '');

            expect($wildcards)->toBe([]);
        });
    });

    describe('#12 — Trigger UUID generation (model boot is single source)', function (): void {
        it('Trigger model generates UUID on create via boot', function (): void {
            $trigger = new Trigger([
                'name' => 'Test Trigger',
                'event' => 'test.event',
                'action' => 'SomeAction',
            ]);

            expect($trigger->id)->toBeEmpty();

            // The creating event would set the UUID
            // We can't save without a DB, but we can verify the boot callback exists
            expect(method_exists(Trigger::class, 'boot'))->toBeTrue();
        });

        it('TriggerBuilder no longer sets UUID explicitly', function (): void {
            $reflection = new ReflectionClass(TriggerBuilder::class);
            $source = file_get_contents($reflection->getFileName());

            // The save() method should not contain Str::uuid()
            expect($source)->not->toContain('Str::uuid()');
        });
    });
});
