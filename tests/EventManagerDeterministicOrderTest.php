<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use ZeroBoiler\Events\Models\Trigger;


describe('EventManager deterministic ordering', function (): void {
    beforeEach(function (): void {
        Bus::fake();
        Trigger::query()->delete();
    });

    test('listTriggers returns deterministic order with created_at tiebreaker', function (): void {
        $triggers = [];
        for ($i = 0; $i < 5; $i++) {
            $triggers[] = Trigger::factory()->create([
                'event' => 'ordering.test',
                'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
                'enabled' => true,
                'async' => false,
                'priority' => 50,
            ]);
        }

        $results = \ZeroBoiler\Events\Facades\EventManager::listTriggers('ordering.test');

        expect($results->count())->toBe(5);

        // Results should be ordered by created_at ASC (all same priority)
        $ids = $results->pluck('id')->toArray();
        $sortedIds = $ids;
        sort($sortedIds);

        // Since all priorities are the same, order should be by created_at
        // which correlates with insertion order for in-memory SQLite
        $createdAts = $results->pluck('created_at')->map(fn ($d) => $d->timestamp)->toArray();
        for ($i = 1; $i < count($createdAts); $i++) {
            expect($createdAts[$i])->toBeGreaterThanOrEqual($createdAts[$i - 1]);
        }
    });

    test('listTriggers respects priority ordering with created_at tiebreaker', function (): void {
        $high1 = Trigger::factory()->create([
            'event' => 'priority.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
            'priority' => 100,
        ]);

        $low1 = Trigger::factory()->create([
            'event' => 'priority.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
            'priority' => 10,
        ]);

        $high2 = Trigger::factory()->create([
            'event' => 'priority.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
            'priority' => 100,
        ]);

        $results = \ZeroBoiler\Events\Facades\EventManager::listTriggers('priority.test');

        expect($results->count())->toBe(3);

        // First two should be priority 100 (ordered by created_at), then priority 10
        expect($results[0]->priority)->toBe(100);
        expect($results[1]->priority)->toBe(100);
        expect($results[2]->priority)->toBe(10);

        // Within same priority, created_at should be ascending
        expect($results[0]->created_at->timestamp)
            ->toBeLessThanOrEqual($results[1]->created_at->timestamp);
    });
});
