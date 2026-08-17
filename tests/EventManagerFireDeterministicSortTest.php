<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\HighPriority;
use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\Tests\Actions\LowPriority;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use ZeroBoiler\Events\EventManager as EventManagerInstance;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('EventManager getMatchingTriggers deterministic sort order', function (): void {
    it('sorts triggers by priority DESC, then created_at ASC, then ID as final tiebreaker', function (): void {
        // Create 3 triggers with same priority via factory
        $trigger1 = Trigger::factory()->create([
            'event' => 'sort.test',
            'action' => SendOrderNotification::class,
            'enabled' => true,
            'priority' => 50,
        ]);

        $trigger2 = Trigger::factory()->create([
            'event' => 'sort.test',
            'action' => LogOrderEvent::class,
            'enabled' => true,
            'priority' => 50,
        ]);

        $trigger3 = Trigger::factory()->create([
            'event' => 'sort.test',
            'action' => HighPriority::class,
            'enabled' => true,
            'priority' => 50,
        ]);

        // Use reflection to access protected getMatchingTriggers (PHP 8.5: setAccessible no longer needed)
        $manager = app(EventManagerInstance::class);
        $reflection = new ReflectionMethod($manager, 'getMatchingTriggers');
        $result = $reflection->invoke($manager, 'sort.test');

        // All three should be returned
        expect($result)->toHaveCount(3);

        // Verify ordering is deterministic — extract IDs in order
        $ids = $result->map(fn (Trigger $t): string => $t->id)->values()->toArray();

        // The sort should be: same priority → created_at ASC → id ASC
        // Since created_at differs by at least a microsecond, ordering by
        // created_at ASC should produce chronological order
        // The final tiebreaker is ID, which should also be in ascending order
        // if created_at is the same
        $sortedIds = [...$ids];
        sort($sortedIds);

        // IDs should be in ascending order (final tiebreaker)
        expect($ids)->toBe($sortedIds);
    });

    it('higher priority triggers always come first regardless of created_at', function (): void {
        // Low priority first
        $low = Trigger::factory()->create([
            'event' => 'priority.test',
            'action' => LowPriority::class,
            'enabled' => true,
            'priority' => 10,
        ]);

        // High priority second
        $high = Trigger::factory()->create([
            'event' => 'priority.test',
            'action' => HighPriority::class,
            'enabled' => true,
            'priority' => 100,
        ]);

        $manager = app(EventManagerInstance::class);
        $reflection = new ReflectionMethod($manager, 'getMatchingTriggers');
        $result = $reflection->invoke($manager, 'priority.test');

        expect($result)->toHaveCount(2);
        expect($result->first()->id)->toBe($high->id);
        expect($result->last()->id)->toBe($low->id);
    });

    it('maintains stable ordering on repeated calls', function (): void {
        $triggers = [];
        for ($i = 0; $i < 5; $i++) {
            $triggers[] = Trigger::factory()->create([
                'event' => 'stable.sort.test',
                'action' => SendOrderNotification::class,
                'enabled' => true,
                'priority' => 50,
            ]);
        }

        $manager = app(EventManagerInstance::class);
        $reflection = new ReflectionMethod($manager, 'getMatchingTriggers');

        $ids1 = $reflection->invoke($manager, 'stable.sort.test')
            ->map(fn (Trigger $t): string => $t->id)
            ->values()
            ->toArray();

        $ids2 = $reflection->invoke($manager, 'stable.sort.test')
            ->map(fn (Trigger $t): string => $t->id)
            ->values()
            ->toArray();

        // Same result on repeated calls
        expect($ids1)->toBe($ids2);
    });
});
