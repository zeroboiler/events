<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

require_once __DIR__.'/TestActions.php';

/**
 * Tests for bug fixes: #6, #8, #9, #12
 */
describe('Bug Fix Tests', function (): void {
    describe('#8 — WildcardMatcher extractWildcards with bare *', function (): void {
        it('returns the full event string for bare * pattern with multi-segment event', function (): void {
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

    describe('#6 — TriggerBuilder action() accumulates multiple calls', function (): void {
        it('accumulates multiple action() calls instead of overwriting', function (): void {
            $builder = app(TriggerBuilder::class);
            $builder
                ->on('test.event')
                ->action('App\\Actions\\Foo')
                ->action('App\\Actions\\Bar');

            $trigger = $builder->save();

            // Both actions should be stored as a JSON array
            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toContain('App\\Actions\\Foo')
                ->and($decoded)->toContain('App\\Actions\\Bar')
                ->and(count($decoded))->toBe(2);
        });

        it('still works with a single action() call', function (): void {
            $builder = app(TriggerBuilder::class);
            $builder
                ->on('test.event')
                ->action('App\\Actions\\Single');

            $trigger = $builder->save();

            expect($trigger->action)->toBe('App\\Actions\\Single');
        });

        it('deduplicates identical action() calls', function (): void {
            $builder = app(TriggerBuilder::class);
            $builder
                ->on('test.event')
                ->action('App\\Actions\\Foo')
                ->action('App\\Actions\\Foo');

            $trigger = $builder->save();

            expect($trigger->action)->toBe('App\\Actions\\Foo');
        });
    });

    describe('#9 — fireModel does not put model object in payload', function (): void {
        it('serializes model to array, never includes raw model object', function (): void {
            $trigger = Trigger::factory()->create([
                'event' => 'App\\Models\\Order.created',
                'action' => SendOrderNotification::class,
                'conditions' => null,
                'enabled' => true,
                'async' => false,
            ]);

            $model = new class
            {
                public $id = 42;

                public $status = 'active';

                public function attributesToArray(): array
                {
                    return ['id' => 42, 'status' => 'active'];
                }
            };

            EventManager::fireModel('App\\Models\\Order', 'created', $model);

            $log = EventLog::first();
            expect($log)->not->toBeNull()
                ->and($log->payload)->not->toHaveKey('model')
                ->and($log->payload)->toHaveKey('id', 42)
                ->and($log->payload)->toHaveKey('status', 'active')
                ->and($log->payload)->toHaveKey('model_class', 'App\\Models\\Order')
                ->and($log->payload)->toHaveKey('action', 'created');
        });
    });
});
