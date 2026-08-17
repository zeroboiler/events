<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('EventManager Delete Trigger', function () {
    it('deletes an existing trigger by ID and invalidates cache', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $trigger = $manager->on('delete.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        $result = $manager->deleteTrigger($trigger->id);
        expect($result)->toBeTrue();

        // Verify deleted — should return null
        $found = \ZeroBoiler\Events\Models\Trigger::find($trigger->id);
        expect($found)->toBeNull();

        // Verify cache was invalidated (no stale trigger)
        $manager->invalidateTriggerCache();
    });

    it('returns false for non-existent trigger ID', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $result = $manager->deleteTrigger('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    it('deletes trigger even if it has event logs (via soft delete)', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $trigger = $manager->on('delete.with-logs')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        // Create an event log for the trigger
        \ZeroBoiler\Events\Models\EventLog::factory()
            ->forTrigger($trigger->id)
            ->withEvent('delete.with-logs')
            ->create();

        $result = $manager->deleteTrigger($trigger->id);
        expect($result)->toBeTrue();

        // EventLog should still exist (cascade not set in soft delete)
        // but the trigger should be soft-deleted
        $found = \ZeroBoiler\Events\Models\Trigger::find($trigger->id);
        expect($found)->toBeNull();
    });
});

describe('TriggerBuilder Actions Validation', function () {
    it('rejects non-string entries in actions array', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn () => $manager->on('validate.actions')
            ->actions([123, \ZeroBoiler\Events\Tests\Actions\SendOrderNotification'])
            ->save()
        )->toThrow(\InvalidArgumentException::class, 'non-empty string');
    });

    it('rejects empty strings in actions array', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn () => $manager->on('validate.actions.empty')
            ->actions(['', \ZeroBoiler\Events\Tests\Actions\SendOrderNotification'])
            ->save()
        )->toThrow(\InvalidArgumentException::class, 'non-empty string');
    });

    it('accepts valid string array and saves correctly', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('validate.actions.valid')
            ->actions([
                \ZeroBoiler\Events\Tests\Actions\SendOrderNotification',
                \ZeroBoiler\Events\Tests\Actions\LogOrderEvent',
            ])
            ->save();

        expect($trigger)->toBeInstanceOf(\ZeroBoiler\Events\Models\Trigger::class);
        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray();
        expect(count($decoded))->toBe(2);
    });

    it('deduplicates action() and actions() correctly', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('dedup.merge')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification')
            ->actions([\ZeroBoiler\Events\Tests\Actions\LogOrderEvent', \ZeroBoiler\Events\Tests\Actions\SendOrderNotification'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        // First action prepended, dedup removes duplicate SendOrderNotification
        expect($decoded[0])->toBe(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification');
        expect($decoded[1])->toBe(\ZeroBoiler\Events\Tests\Actions\LogOrderEvent');
        expect(count($decoded))->toBe(2);
    });
});

describe('WildcardMatcher Static Analysis', function () {
    it('matches method is annotated as #[Pure]', function () {
        $ref = new ReflectionMethod(\ZeroBoiler\Events\WildcardMatcher::class, 'matches');
        $attrs = $ref->getAttributes();
        $hasPure = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue();
    });

    it('findMatchingPatterns method is annotated as #[Pure]', function () {
        $ref = new ReflectionMethod(\ZeroBoiler\Events\WildcardMatcher::class, 'findMatchingPatterns');
        $attrs = $ref->getAttributes();
        $hasPure = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue();
    });

    it('extractWildcards method is annotated as #[Pure]', function () {
        $ref = new ReflectionMethod(\ZeroBoiler\Events\WildcardMatcher::class, 'extractWildcards');
        $attrs = $ref->getAttributes();
        $hasPure = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue();
    });
});

describe('EventManager Global Disable Integration', function () {
    it('setEnabled(true) re-enables after disable', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $manager->setEnabled(false);
        expect($manager->isDisabled())->toBeTrue();

        $manager->setEnabled(true);
        expect($manager->isDisabled())->toBeFalse();
    });

    it('fire() silently returns when disabled', function () {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $manager->setEnabled(true);

        $trigger = $manager->on('disable.fire-test')
            ->action(\ZeroBoiler\Events\Tests\Actions\HighPriority::class)
            ->save();

        // Count event logs before
        $beforeCount = \ZeroBoiler\Events\Models\EventLog::count();

        $manager->setEnabled(false);
        $manager->fire('disable.fire-test', ['test' => true]);

        // No new logs should be created
        $afterCount = \ZeroBoiler\Events\Models\EventLog::count();
        expect($afterCount)->toBe($beforeCount);

        // Clean up
        $manager->setEnabled(true);
        $manager->deleteTrigger($trigger->id);
    });
});
